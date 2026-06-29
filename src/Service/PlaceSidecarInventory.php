<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\SidecarInventory;
use Fisharebest\Webtrees\Tree;

/**
 * Zählt den kuratorischen Bestand eines Orts (Notizen, Aufgaben, KB,
 * Digitalisate, GOV) — für das Merge-Modal (Richtungs-Entscheidung) und
 * später die Discovery-Queue.
 *
 * Reine Lese-Operation. Nutzt die bestehenden Sidecar-Services wieder, damit
 * die Zähl-Logik nicht von deren Datei-Konventionen abdriftet.
 */
final class PlaceSidecarInventory
{
    public function __construct(
        private readonly PlaceNotesService  $notes,
        private readonly PlaceTasksService  $tasks,
        private readonly PlaceKbListService $kbs,
        private readonly PlaceFolderScanner $scanner,
        private readonly GovLinkingService  $gov,
    ) {}

    /**
     * @param int    $placeId webtrees-place_id (für GOV/place_meta)
     * @param string $leaf    Blatt-Name (für den Sidecar-Ordner)
     */
    public function forPlace(Tree $tree, int $placeId, string $leaf): SidecarInventory
    {
        if (trim($leaf) === '') {
            return SidecarInventory::empty();
        }

        $markdownFiles = count($this->notes->scanMarkdownFiles($tree, $leaf));
        $taskCount     = count($this->tasks->read($tree, $leaf));
        $kbCount       = count($this->kbs->read($tree, $leaf));

        // Digitalisate = gescannte Dateien ohne Markdown (notes.md etc. zählen
        // als Notizen, nicht als Digitalisat).
        $digitalisate = 0;
        foreach ($this->scanner->scan($tree, $leaf) as $file) {
            if ($file->extension !== 'md') {
                $digitalisate++;
            }
        }

        $govLinked = $this->gov->getLinkedGovId($tree, $placeId) !== null;

        return new SidecarInventory($markdownFiles, $taskCount, $kbCount, $digitalisate, $govLinked);
    }
}
