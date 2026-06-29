<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Ergebnis von PlaceOperationService::analyzeMerge().
 *
 * Liefert alle Informationen die das Resolve-Modal anzeigen muss:
 * - betroffene Records (INDI/FAM/SOUR/OBJE) mit Counts
 * - Subtag-Konflikte die der User entscheiden muss
 * - vorhandene Vesta-`_LOC`-Verweise (Hinweis im Modal)
 */
final class MergeAnalysis
{
    /**
     * @param array<string, int>     $affectedCounts  Record-Type → Anzahl, z.B. ['INDI' => 42, 'FAM' => 7]
     * @param list<SubtagConflict>   $conflicts
     * @param list<string>           $warnings        z.B. „Quell- oder Ziel-PLAC verweist auf Vesta-_LOC-Record, der dadurch verwaist."
     */
    public function __construct(
        public readonly int    $sourcePlaceId,
        public readonly int    $targetPlaceId,
        public readonly string $sourceValue,
        public readonly string $targetValue,
        public readonly array  $affectedCounts,
        public readonly array  $conflicts,
        public readonly array  $warnings,

        /** Kuratorischer Bestand der Quelle (was beim Merge mitwandert). */
        public readonly SidecarInventory $sourceSidecar,
        /** Kuratorischer Bestand des Ziels. */
        public readonly SidecarInventory $targetSidecar,
    ) {}

    public function totalAffected(): int
    {
        return array_sum($this->affectedCounts);
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
