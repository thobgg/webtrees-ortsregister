<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Was der `LocationWriter` für einen Ort TUN WÜRDE — reiner Plan, noch nichts
 * geschrieben. Basis für die Preview (nie still schreiben) und für `execute()`.
 *
 * Vier Aktionen:
 *   CREATE    — kein `_LOC` vorhanden → neuen Record anlegen (Body in $facts).
 *   UPDATE    — genau ein `_LOC` vorhanden → additiv Lücken füllen (Gap-Facts in $facts).
 *   NONE      — `_LOC` vorhanden, aber schon vollständig (nichts zu tun).
 *   AMBIGUOUS — mehrere `_LOC` mit passendem Namen → User muss wählen ($candidates).
 *
 * Gap-fill-only: bestehende, abweichende Werte werden NIE überschrieben, sondern
 * als $conflicts gemeldet (der User entscheidet manuell).
 */
final class LocWritePlan
{
    public const ACTION_CREATE    = 'create';
    public const ACTION_UPDATE    = 'update';
    public const ACTION_NONE      = 'none';
    public const ACTION_AMBIGUOUS = 'ambiguous';

    /**
     * @param list<string>                     $facts       zu schreibende Fact-Blöcke (je `1 …`, MAP inkl. `2 LATI/2 LONG`)
     * @param list<string>                     $conflicts   menschlich lesbare Konflikt-Hinweise (werden NICHT geschrieben)
     * @param list<array{xref:string,name:string}> $candidates bei AMBIGUOUS: die passenden `_LOC`-Records
     */
    public function __construct(
        public readonly string  $action,
        public readonly int     $placeId,
        public readonly string  $placeName,
        public readonly ?string $targetXref,   // bei UPDATE: der zu ergänzende Record
        public readonly array   $facts = [],
        public readonly array   $conflicts = [],
        public readonly array   $candidates = [],
    ) {}

    /** Schreibt dieser Plan tatsächlich etwas? */
    public function willWrite(): bool
    {
        return ($this->action === self::ACTION_CREATE || $this->action === self::ACTION_UPDATE)
            && $this->facts !== [];
    }

    /**
     * Das exakte GEDCOM, das geschrieben wird — für die Preview.
     * CREATE: kompletter Record. UPDATE: nur die anzuhängenden Fact-Blöcke.
     */
    public function gedcomPreview(): string
    {
        if ($this->facts === []) {
            return '';
        }
        $body = implode("\n", $this->facts);
        return $this->action === self::ACTION_CREATE ? "0 @@ _LOC\n" . $body : $body;
    }
}
