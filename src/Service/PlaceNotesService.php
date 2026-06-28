<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\PlaceNotes;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use League\CommonMark\CommonMarkConverter;
use RuntimeException;

/**
 * Liest/schreibt `notes.md` im Ortsbilder-Ordner und rendert Markdown→HTML.
 *
 * Konvention: `media/<root>/<ortsname>/notes.md`
 *
 * Optimistic Locking via filemtime: der Caller bekommt beim Read den mtime
 * mit und gibt ihn beim Save zurück. Bei Mismatch wirft save() RuntimeException.
 */
class PlaceNotesService
{
    public const FILENAME = 'notes.md';

    private ?CommonMarkConverter $converter = null;

    public function __construct(
        private readonly string $folderRoot = 'orte',
    ) {}

    public function read(Tree $tree, string $placeName): PlaceNotes
    {
        $path = $this->absolutePath($tree, $placeName);
        if ($path === null || !is_file($path)) {
            return PlaceNotes::empty();
        }
        $md    = (string) @file_get_contents($path);
        $mtime = @filemtime($path) ?: 0;
        return new PlaceNotes($md, $mtime);
    }

    /**
     * Schreibt notes.md. Liefert neuen mtime zurück.
     * Wirft RuntimeException bei mtime-Mismatch (parallel edit).
     */
    public function save(Tree $tree, string $placeName, string $markdown, int $expectedMtime): int
    {
        $path = $this->absolutePath($tree, $placeName);
        if ($path === null) {
            throw new RuntimeException('Ungültiger Ortsname.');
        }
        // Ordner ggf. anlegen
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Ordner konnte nicht angelegt werden: ' . $dir);
        }

        // Optimistic-Lock-Check
        $currentMtime = is_file($path) ? (filemtime($path) ?: 0) : 0;
        if ($expectedMtime !== 0 && $currentMtime !== 0 && $currentMtime !== $expectedMtime) {
            throw new RuntimeException(
                'Die Notizen wurden zwischenzeitlich anderswo geändert. Bitte Seite neu laden und erneut versuchen.'
            );
        }

        // Bei leerem Inhalt: Datei löschen, nicht leer schreiben
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
     * Render Markdown → HTML. Default-CommonMark mit konservativen Einstellungen
     * (kein raw HTML, kein unsafe links).
     */
    public function render(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        if ($this->converter === null) {
            $this->converter = new CommonMarkConverter([
                'html_input'         => 'escape',  // Roh-HTML escapen, keine Injection
                'allow_unsafe_links' => false,
            ]);
        }
        return (string) $this->converter->convert($markdown);
    }

    /**
     * Liefert den absoluten Pfad zur notes.md, oder null bei ungültigem Ortsnamen.
     */
    private function absolutePath(Tree $tree, string $placeName): ?string
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
        return Webtrees::DATA_DIR . $mediaDir . trim($this->folderRoot, '/') . '/' . $placeName . '/' . self::FILENAME;
    }
}
