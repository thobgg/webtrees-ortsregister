<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\PlaceFile;
use Fisharebest\Webtrees\Tree;

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
        private readonly PlaceFolderLocator $folderLocator = new PlaceFolderLocator(),
    ) {}

    /**
     * @return list<PlaceFile>
     */
    public function scan(Tree $tree, string $placeName): array
    {
        // Ort->Ordner-Auflösung + Path-Traversal-Schutz: einzige Naht.
        $absoluteDir = $this->folderLocator->folder($tree, $placeName);
        $relativeDir = $this->folderLocator->relativeFolder($tree, $placeName);
        if ($absoluteDir === null || $relativeDir === null || !is_dir($absoluteDir)) {
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
