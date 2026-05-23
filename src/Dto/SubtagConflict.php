<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Konflikt zwischen einem Subtag in Quell- und Ziel-PLAC beim Merge.
 *
 * Beim Merge zweier Orte vergleicht der PlaceOperationService die direkten
 * Subtags des Quell-PLAC mit denen des Ziel-PLAC. Wenn derselbe Tag-Name
 * (z.B. `_GOV`, `_LOC`, `MAP`) auf beiden Seiten mit unterschiedlichem
 * Wert vorkommt, entsteht ein SubtagConflict. Der User muss im Resolve-
 * Modal pro Konflikt entscheiden.
 *
 * Default ist 'target' — Architektur-Konsens „GEDCOM persistent" + Vesta-
 * Karteileichen-Strategie „Ziel behalten".
 */
final class SubtagConflict
{
    public const RESOLUTION_TARGET = 'target';
    public const RESOLUTION_SOURCE = 'source';
    public const RESOLUTION_DROP   = 'drop';

    public function __construct(
        /** Tag-Name, z.B. `_GOV` oder `MAP` */
        public readonly string $tag,

        /** Wert auf der Quell-Seite (kann mehrzeilig sein bei Sub-Subtags) */
        public readonly string $sourceValue,

        /** Wert auf der Ziel-Seite */
        public readonly string $targetValue,
    ) {}
}
