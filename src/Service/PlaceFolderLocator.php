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
        $leafName = $this->safeLeaf($leafName);
        return $leafName === null ? null : $this->root($tree) . '/' . $leafName;
    }

    /**
     * Absoluter Wurzelpfad `<data>/<media>/<root>` (ohne abschliessenden Slash).
     * Für Ablagen die NICHT an einem einzelnen Ort hängen (z.B. globale
     * Archion-Map `_archion-urls.json`).
     */
    public function root(Tree $tree): string
    {
        $mediaDir = $tree->getPreference('MEDIA_DIRECTORY', 'media/');
        return Webtrees::DATA_DIR . $mediaDir . trim($this->folderRoot, '/');
    }

    /**
     * Ordnerpfad relativ zu MEDIA_DIRECTORY (`<root>/<blattname>`) oder null bei
     * unsicherem Namen. Für Verweise die relativ bleiben müssen (z.B.
     * `PlaceFile::relativePath` → MediaDateiServe `?pfad=…`).
     */
    public function relativeFolder(Tree $tree, string $leafName): ?string
    {
        $leafName = $this->safeLeaf($leafName);
        return $leafName === null ? null : trim($this->folderRoot, '/') . '/' . $leafName;
    }

    /**
     * Trimmt + prüft den Blattnamen (Path-Traversal-Schutz). null = unsicher/leer.
     */
    private function safeLeaf(string $leafName): ?string
    {
        $leafName = trim($leafName);
        if ($leafName === ''
            || str_contains($leafName, '/')
            || str_contains($leafName, '\\')
            || str_contains($leafName, '..')
        ) {
            return null;
        }
        return $leafName;
    }
}
