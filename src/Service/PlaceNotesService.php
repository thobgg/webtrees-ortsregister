<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\PlaceNotes;
use Fisharebest\Webtrees\Http\RequestHandlers\IndividualPage;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use RuntimeException;

/**
 * Liest/schreibt Markdown-Notiz-Dateien im Ortsbilder-Ordner und rendert
 * Markdown → HTML (GitHub-Flavored, inkl. Task-Lists, Tabellen, Autolinks).
 *
 * Konvention: `media/<root>/<ortsname>/<filename>.md`
 *
 * Standard-Slots (vom Modul mit Default-Titel + Placeholder versorgt):
 *   notes.md, recherche.md, personen.md
 *
 * Plus: alle weiteren `*.md` im Ortsordner werden automatisch erkannt
 * (User-definierbar) — `scanMarkdownFiles()` listet sie auf.
 *
 * Filename-Whitelist `^[a-z][a-z0-9_-]*\.md$`: keine Pfade, keine
 * versteckten Dateien, keine .md.bak-Tricks.
 *
 * Optimistic Locking via filemtime: Caller bekommt mtime beim Read mit
 * und gibt ihn beim Save zurück. Mismatch → RuntimeException.
 */
class PlaceNotesService
{
    /** Filename-Validierungs-Pattern. */
    private const FILENAME_PATTERN = '/^[a-z][a-z0-9_-]*\.md$/';

    private ?GithubFlavoredMarkdownConverter $converter = null;

    public function __construct(
        private readonly string $folderRoot = 'orte',
    ) {}

    public function read(Tree $tree, string $placeName, string $filename = 'notes.md'): PlaceNotes
    {
        $path = $this->absolutePath($tree, $placeName, $filename);
        if ($path === null || !is_file($path)) {
            return PlaceNotes::empty();
        }
        $md    = (string) @file_get_contents($path);
        $mtime = @filemtime($path) ?: 0;
        return new PlaceNotes($md, $mtime);
    }

    /**
     * Schreibt Markdown-File. Liefert neuen mtime zurück.
     * Wirft RuntimeException bei mtime-Mismatch (parallel edit).
     */
    public function save(
        Tree $tree,
        string $placeName,
        string $markdown,
        int $expectedMtime,
        string $filename = 'notes.md',
    ): int {
        $path = $this->absolutePath($tree, $placeName, $filename);
        if ($path === null) {
            throw new RuntimeException('Ungültiger Ortsname oder Filename.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Ordner konnte nicht angelegt werden: ' . $dir);
        }

        $currentMtime = is_file($path) ? (filemtime($path) ?: 0) : 0;
        if ($expectedMtime !== 0 && $currentMtime !== 0 && $currentMtime !== $expectedMtime) {
            throw new RuntimeException(
                'Die Notizen wurden zwischenzeitlich anderswo geändert. Bitte Seite neu laden und erneut versuchen.'
            );
        }

        if (trim($markdown) === '') {
            if (is_file($path)) {
                @unlink($path);
            }
            return 0;
        }

        $ok = @file_put_contents($path, $markdown, LOCK_EX);
        if ($ok === false) {
            throw new RuntimeException('Schreiben fehlgeschlagen: ' . $path);
        }
        clearstatcache(true, $path);
        return filemtime($path) ?: time();
    }

    /**
     * Listet alle vorhandenen `.md`-Dateien im Ortsordner (sortiert).
     * Nur Whitelist-konforme Filenames.
     *
     * @return list<string>
     */
    public function scanMarkdownFiles(Tree $tree, string $placeName): array
    {
        $dir = $this->placeFolder($tree, $placeName);
        if ($dir === null || !is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match(self::FILENAME_PATTERN, $entry) === 1 && is_file($dir . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Render Markdown → HTML mit GitHub-Flavored-Markdown
     * (Task-Lists, Tabellen, Strikethrough, Autolinks).
     * Roh-HTML wird escapet (sicher), unsafe-Links blockiert.
     *
     * Wenn $tree gesetzt: `indi:`-Links auf webtrees-Personen werden zu
     * echten Individual-URLs aufgelöst; Checkboxen für GFM-Task-Lists
     * werden mit Indices instrumentiert (für interaktiven Toggle).
     */
    public function render(string $markdown, ?Tree $tree = null): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        if ($this->converter === null) {
            $this->converter = new GithubFlavoredMarkdownConverter([
                'html_input'         => 'escape',
                'allow_unsafe_links' => false,
            ]);
        }
        $html = (string) $this->converter->convert($markdown);
        if ($tree !== null) {
            $html = $this->resolveIndiLinks($html, $tree);
        }
        $html = $this->instrumentCheckboxes($html);
        $html = $this->markPriorityAndDates($html);
        return $html;
    }

    /**
     * Post-Processor für Task-Listen-Konventionen:
     *   `!!`  am Anfang des Task-Texts → Prio HIGH (rot)
     *   `!`   am Anfang → Prio MEDIUM (orange)
     *   `@YYYY-MM-DD` / `@YYYY-MM` / `@YYYY` → Datum-Badge
     *
     * Die `!`/`@`-Marker werden aus dem sichtbaren Text entfernt und durch
     * Badges ersetzt. CSS in der View kümmert sich um Farben.
     */
    private function markPriorityAndDates(string $html): string
    {
        // Datum-Badges (vor Prio, damit die Marker beim Prio-Parse nicht stören)
        $html = (string) preg_replace_callback(
            '/@(\d{4}(?:-\d{2}(?:-\d{2})?)?)\b/',
            static fn(array $m) => '<span class="ortsregister-task-date">📅 ' . htmlspecialchars($m[1], ENT_QUOTES) . '</span>',
            $html,
        );

        // Prio-Marker: nur in <li>-Elementen die Checkboxen enthalten (Task-Lists)
        // Pattern: <li> ... <input type="checkbox" ...> (whitespace) (!!? text...)</li>
        $html = (string) preg_replace_callback(
            '#(<li[^>]*>\s*<input[^>]*type="checkbox"[^>]*>)(\s*)(!!?)(\s+)#',
            static function (array $m): string {
                $marks = $m[3]; // "!" oder "!!"
                $cls   = strlen($marks) === 2 ? 'ortsregister-task-prio-high' : 'ortsregister-task-prio-med';
                $label = strlen($marks) === 2 ? 'hoch' : 'mittel';
                return $m[1] . $m[2]
                    . '<span class="' . $cls . '" title="Priorität ' . $label . '">' . $marks . '</span>'
                    . $m[4];
            },
            $html,
        );
        return $html;
    }

    /**
     * Findet `<a href="indi:Ixxx">Text</a>` und ersetzt durch echten webtrees-Link.
     * Wenn die Person nicht existiert → grauer Hinweis statt Link.
     */
    private function resolveIndiLinks(string $html, Tree $tree): string
    {
        return (string) preg_replace_callback(
            '#<a href="indi:([A-Za-z0-9_-]+)"[^>]*>([^<]*)</a>#',
            static function (array $m) use ($tree): string {
                $xref = strtoupper($m[1]);
                if (!str_starts_with($xref, 'I')) {
                    $xref = 'I' . $xref;
                }
                $name = $m[2] !== '' ? $m[2] : $xref;
                $indi = Registry::individualFactory()->make($xref, $tree);
                if ($indi === null || !$indi->canShow()) {
                    return '<span class="text-muted" title="' . htmlspecialchars($xref, ENT_QUOTES) . ' nicht in webtrees gefunden">'
                        . htmlspecialchars($name, ENT_QUOTES) . ' ⚠️</span>';
                }
                $url = route(IndividualPage::class, ['tree' => $tree->name(), 'xref' => $xref]);
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES)
                    . '" title="webtrees ' . htmlspecialchars($xref, ENT_QUOTES) . '">'
                    . htmlspecialchars($name, ENT_QUOTES) . '</a>';
            },
            $html,
        );
    }

    /**
     * Macht GFM-Task-List-Checkboxen klickbar (entfernt `disabled`, fügt data-task-index hinzu).
     * Indices laufen pro HTML-Block, Reihenfolge = Markdown-Reihenfolge.
     */
    private function instrumentCheckboxes(string $html): string
    {
        $i = 0;
        return (string) preg_replace_callback(
            '#<input[^>]*type="checkbox"[^>]*>#',
            static function (array $m) use (&$i): string {
                $tag = $m[0];
                $tag = (string) preg_replace('/\s*disabled(="[^"]*")?\s*/', ' ', $tag);
                $tag = preg_replace('#>$#', ' data-task-index="' . $i . '" class="ortsregister-task-cb">', $tag) ?? $tag;
                $i++;
                return $tag;
            },
            $html,
        );
    }

    /**
     * Toggelt eine Checkbox in der Markdown-Quelle (Index in Reihenfolge).
     * Liefert das geänderte Markdown zurück, ohne zu speichern.
     */
    public function toggleTaskInMarkdown(string $markdown, int $taskIndex, bool $checked): string
    {
        $lines = preg_split('/\r?\n/', $markdown) ?: [];
        $found = 0;
        foreach ($lines as $idx => $line) {
            if (preg_match('/^(\s*[-*+]\s+)\[([ xX])\](\s.*)?$/', $line, $m) === 1) {
                if ($found === $taskIndex) {
                    $newMarker  = $checked ? 'x' : ' ';
                    $rest       = $m[3] ?? '';
                    $lines[$idx] = $m[1] . '[' . $newMarker . ']' . $rest;
                    break;
                }
                $found++;
            }
        }
        return implode("\n", $lines);
    }

    public function isValidFilename(string $filename): bool
    {
        return preg_match(self::FILENAME_PATTERN, $filename) === 1;
    }

    private function placeFolder(Tree $tree, string $placeName): ?string
    {
        $placeName = trim($placeName);
        if ($placeName === ''
            || str_contains($placeName, '/')
            || str_contains($placeName, '\\')
            || str_contains($placeName, '..')
        ) {
            return null;
        }
        $mediaDir = $tree->getPreference('MEDIA_DIRECTORY', 'media/');
        return Webtrees::DATA_DIR . $mediaDir . trim($this->folderRoot, '/') . '/' . $placeName;
    }

    private function absolutePath(Tree $tree, string $placeName, string $filename): ?string
    {
        if (!$this->isValidFilename($filename)) {
            return null;
        }
        $dir = $this->placeFolder($tree, $placeName);
        return $dir === null ? null : $dir . '/' . $filename;
    }
}
