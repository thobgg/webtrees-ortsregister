<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Fisharebest\Webtrees\I18N;

/**
 * Registriert die Modul-eigenen Übersetzungen in webtrees.
 *
 * webtrees nutzt intern gettext-kompatible .mo-Dateien.
 * Dieser Service sucht im resources/lang/-Verzeichnis nach
 * einer passenden .mo-Datei für die aktive Sprache und
 * kompiliert die .po-Quelle bei Bedarf automatisch.
 *
 * Verzeichnisstruktur:
 *   resources/lang/
 *     de.po    ← Quell-Datei (UTF-8, msgfmt-kompatibel)
 *     de.mo    ← Kompilat (automatisch erzeugt, gitignore)
 *     en.po    ← Englisch als Fallback (Optional)
 */
class I18nService
{
    /** Unterstützte Sprachkürzel → Dateiname */
    private const SUPPORTED = [
        'de'    => 'de',
        'de_AT' => 'de',
        'de_CH' => 'de',
        'en'    => 'en',
        'en_US' => 'en',
        'en_GB' => 'en',
    ];

    public function __construct(
        private readonly string $langDir
    ) {}

    // ---------------------------------------------------------------
    // Öffentliche API
    // ---------------------------------------------------------------

    /**
     * Lädt die .mo-Datei für die aktive Sprache in webtrees
     * und gibt den Pfad zur .mo-Datei zurück (für I18N::addTranslation).
     *
     * Gibt null zurück wenn keine passende Übersetzung gefunden wurde.
     */
    public function moPath(): ?string
    {
        $locale  = I18N::languageTag();  // z. B. „de", „en-US"
        $lang    = str_replace('-', '_', $locale);
        $base    = self::SUPPORTED[$lang]
                ?? self::SUPPORTED[substr($lang, 0, 2)]
                ?? null;

        if ($base === null) {
            return null;
        }

        $moPath = $this->langDir . $base . '.mo';
        $poPath = $this->langDir . $base . '.po';

        // .mo kompilieren wenn veraltet oder nicht vorhanden
        if (file_exists($poPath) && $this->moVeraltet($poPath, $moPath)) {
            $this->kompiliere($poPath, $moPath);
        }

        return file_exists($moPath) ? $moPath : null;
    }

    // ---------------------------------------------------------------
    // Kompilierung
    // ---------------------------------------------------------------

    /**
     * Gibt true zurück, wenn die .mo-Datei älter als die .po-Datei ist
     * oder gar nicht existiert.
     */
    private function moVeraltet(string $poPath, string $moPath): bool
    {
        if (!file_exists($moPath)) {
            return true;
        }

        return filemtime($moPath) < filemtime($poPath);
    }

    /**
     * Kompiliert eine .po-Datei zu .mo mit dem System-msgfmt-Befehl.
     *
     * Ist msgfmt nicht verfügbar, wird ein minimales .mo-Gerüst
     * direkt in PHP erzeugt (deckt Hauptteil der Katalog-Header ab).
     */
    private function kompiliere(string $poPath, string $moPath): void
    {
        // Versuch 1: system msgfmt (GNU gettext)
        if ($this->msgfmtVerfuegbar()) {
            $escaped = escapeshellarg($poPath);
            $out     = escapeshellarg($moPath);
            exec("msgfmt --output-file={$out} {$escaped} 2>/dev/null", result_code: $code);

            if ($code === 0 && file_exists($moPath)) {
                return;
            }
        }

        // Versuch 2: PHP-eigene .po → .mo Konvertierung (minimale Implementation)
        $this->kompiliereInPhp($poPath, $moPath);
    }

    private function msgfmtVerfuegbar(): bool
    {
        static $available = null;

        if ($available === null) {
            exec('msgfmt --version 2>/dev/null', result_code: $code);
            $available = ($code === 0);
        }

        return $available;
    }

    /**
     * Minimalimplementierung des .po → .mo Formats.
     *
     * Das MO-Format (GNU gettext binary):
     *   Magic 0x950412de | Revision 0 | Nstring | Ooffset | Toffset | Ssize | Foffset
     *   gefolgt von Tabellen für Original- und Übersetzungs-Offsets.
     *
     * Nur für den Notfall – msgfmt liefert bessere Ergebnisse.
     */
    private function kompiliereInPhp(string $poPath, string $moPath): void
    {
        $entries = $this->parsePo($poPath);

        if ($entries === []) {
            return;
        }

        // Strings zusammenbauen
        $originals    = '';
        $translations = '';
        $origOffsets  = [];
        $transOffsets = [];

        foreach ($entries as [$msgid, $msgstr]) {
            $origOffsets[]  = [strlen($originals),    strlen($msgid)];
            $transOffsets[] = [strlen($translations), strlen($msgstr)];
            $originals    .= $msgid    . "\0";
            $translations .= $msgstr   . "\0";
        }

        $n         = count($entries);
        $headerSize = 28;           // 7 × uint32
        $origTable  = $headerSize;
        $transTable = $origTable  + $n * 8;
        $origBase   = $transTable + $n * 8;
        $transBase  = $origBase   + strlen($originals);

        $mo  = pack('Vvv', 0x950412de, 0, 0);  // magic, major, minor rev
        $mo .= pack('V',  $n);
        $mo .= pack('V',  $origTable);
        $mo .= pack('V',  $transTable);
        $mo .= pack('VV', 0, 0);                // hash table (unused)

        foreach ($origOffsets as [$offset, $len]) {
            $mo .= pack('VV', $len, $origBase + $offset);
        }
        foreach ($transOffsets as [$offset, $len]) {
            $mo .= pack('VV', $len, $transBase + $offset);
        }

        $mo .= $originals . $translations;

        file_put_contents($moPath, $mo);
    }

    /**
     * Parst eine .po-Datei und gibt [msgid, msgstr]-Paare zurück.
     * Unterstützt mehrzeilige Strings und escaped Newlines.
     *
     * @return list<array{string, string}>
     */
    private function parsePo(string $poPath): array
    {
        $content = file_get_contents($poPath);
        if ($content === false) {
            return [];
        }

        $entries = [];
        $msgid   = null;
        $msgstr  = null;
        $inId    = false;
        $inStr   = false;

        foreach (explode("\n", $content) as $raw) {
            $line = trim($raw);

            if (str_starts_with($line, '#') || $line === '') {
                if ($msgid !== null && $msgstr !== null && $msgid !== '') {
                    $entries[] = [$msgid, $msgstr];
                }
                if ($line === '') {
                    $msgid  = null;
                    $msgstr = null;
                    $inId   = false;
                    $inStr  = false;
                }
                continue;
            }

            if (str_starts_with($line, 'msgid ')) {
                $msgid = $this->unquote(substr($line, 6));
                $inId  = true;
                $inStr = false;
                continue;
            }

            if (str_starts_with($line, 'msgstr ')) {
                $msgstr = $this->unquote(substr($line, 7));
                $inId   = false;
                $inStr  = true;
                continue;
            }

            // Fortsetzungszeile „"…""
            if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
                $part = $this->unquote($line);
                if ($inId)  { $msgid  .= $part; }
                if ($inStr) { $msgstr .= $part; }
            }
        }

        // Letzten Eintrag nicht vergessen
        if ($msgid !== null && $msgstr !== null && $msgid !== '') {
            $entries[] = [$msgid, $msgstr];
        }

        return $entries;
    }

    /** Entfernt äußere Anführungszeichen und Escape-Sequenzen. */
    private function unquote(string $s): string
    {
        $s = trim($s);

        if (str_starts_with($s, '"') && str_ends_with($s, '"')) {
            $s = substr($s, 1, -1);
        }

        return stripslashes($s);
    }
}
