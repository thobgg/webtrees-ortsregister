<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Ein Ereignis am `_LOC`-Record (GEDCOM-L `1 EVEN`), rein lesend gespiegelt.
 *
 * Verifizierte Grammatik (webtrees `CustomTags/GedcomL.php`, `_LOC:EVEN`):
 *   1 EVEN
 *   2 TYPE <art>
 *   2 DATE <datum>
 *   2 PLAC <ort>
 *
 * Nur die geläufigen, eindeutig interpretierbaren Kinder werden übernommen
 * (TYPE/DATE/PLAC) — keine Semantik-Raterei über seltene Felder.
 */
final class LocEvent
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $date = null,
        public readonly ?string $place = null,
    ) {}

    /** true, wenn wenigstens ein anzeigbares Feld gesetzt ist. */
    public function hasContent(): bool
    {
        return $this->type !== null || $this->date !== null || $this->place !== null;
    }
}
