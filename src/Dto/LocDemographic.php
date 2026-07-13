<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Eine demografische Angabe am `_LOC`-Record (GEDCOM-L `1 _DMGD`), z.B. eine
 * Einwohnerzahl — rein lesend gespiegelt.
 *
 * Verifizierte Grammatik (webtrees `CustomTags/GedcomL.php`, `_LOC:_DMGD`):
 *   1 _DMGD <wert>          (der Wert steht auf der Tag-Zeile)
 *   2 TYPE  <art>           ("Type of demographic data", z.B. Einwohner)
 *   2 DATE  <datum>
 *
 * Das Modul deutet die Art NICHT um — `$type` wird 1:1 gezeigt (keine
 * Semantik-Annahme, ob „population", „households" o.ä.).
 */
final class LocDemographic
{
    public function __construct(
        public readonly string $value,
        public readonly ?string $type = null,
        public readonly ?string $date = null,
    ) {}
}
