<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\PlaceFile;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;

/**
 * Liest Dateien aus `media/<root>/<ortsname>/` zu einem Ort.
 *
 * - Konvention: pro Ort ein Unterordner mit dem Ortsnamen (Blatt, z.B.
 *   „Haberschlacht"). Existiert der Ordner nicht → leeres Result.
 * - Files brauchen KEINEN webtrees-OBJE-Record — direkt vom Filesystem.
 * - Versteckte Dateien (.*) und Thumbnail-Verzeichnisse (_thumbs/) werden
 *   ignoriert.
 * - Sortierung: alphabetisch nach Dateiname.
 */
class PlaceFolderScanner
{
    private const IGNORED_PREFIXES = ['.', '_'];

    public function __construct(
        private readonly string $folderRoot = 'orte',
    ) {}

    /**
     * @return list<PlaceFile>
     */
    public function scan(Tree $tree, string $placeName): array
    {
        $placeName = trim($placeName);
        if ($placeName === '') {
            return [];
        }

        // Path-Traversal-Schutz: keine "/" oder ".." im Place-Namen
        if (str_contains($placeName, '/') || str_contains($placeName, '\\') || str_contains($placeName, '..')) {
            return [];
        }

        $mediaDir   = $tree->getPreference('MEDIA_DIRECTORY', 'media/');
        $relativeDir = trim($this->folderRoot, '/') . '/' . $placeName;
        $absoluteDir = Webtrees::DATA_DIR . $mediaDir . $relativeDir;

        if (!is_dir($absoluteDir)) {
            return [];
        }

        $files = [];
        foreach (scandir($absoluteDir) ?: [] as $entry) {
            if ($entry === '' || str_starts_with($entry, '.') || str_starts_with($entry, '_')) {
                continue;
            }
            $full = $absoluteDir . '/' . $entry;
            if (!is_file($full)) {
                continue;
            }
            $ext  = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $size = @filesize($full) ?: 0;
            $mt   = @filemtime($full) ?: 0;
            $files[] = new PlaceFile(
                filename:     $entry,
                relativePath: $relativeDir . '/' . $entry,
                extension:    $ext,
                sizeBytes:    $size,
                mtimeUnix:    $mt,
            );
        }
        usort($files, static fn(PlaceFile $a, PlaceFile $b) => strnatcasecmp($a->filename, $b->filename));
        return $files;
    }
}
