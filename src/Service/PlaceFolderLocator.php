<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;

/**
 * Kanonische Auflösung Ort → Sidecar-Ordner (`media/<root>/<blattname>/`).
 *
 * EINZIGE Stelle, die das Folder-Keying kennt. Heute: Blatt-Name (identisch zu
 * PlaceNotesService/PlaceTasksService/PlaceKbListService/PlaceFolderScanner).
 *
 * Naht für die spätere Identitäts-Schicht (Phase 4, GOV-ID/voller Pfad als
 * Schlüssel): NUR diese Klasse wechselt, Merger und Sidecar-Services bleiben
 * unberührt. Siehe Konzept-Memo "resolvePlaceFolder-Naht".
 *
 * Path-Traversal-Schutz identisch zu den Sidecar-Services (kein '/', '\\', '..').
 */
final class PlaceFolderLocator
{
    public function __construct(
        private readonly string $folderRoot = 'orte',
    ) {}

    /**
     * Absoluter Ordnerpfad oder null bei leerem/unsicherem Namen.
     */
    public function folder(Tree $tree, string $leafName): ?string
    {
        $leafName = trim($leafName);
        if ($leafName === ''
            || str_contains($leafName, '/')
            || str_contains($leafName, '\\')
            || str_contains($leafName, '..')
        ) {
            return null;
        }
        $mediaDir = $tree->getPreference('MEDIA_DIRECTORY', 'media/');
        return Webtrees::DATA_DIR . $mediaDir . trim($this->folderRoot, '/') . '/' . $leafName;
    }
}
