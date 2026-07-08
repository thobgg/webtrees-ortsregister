<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Was der `LocationEventLinker` (W2) für einen Ort TUN WÜRDE — reiner Plan, noch
 * nichts geschrieben. Basis für die Preview (nie still schreiben) und für `execute()`.
 *
 * W2 = der Ereignis→Ort-Zeiger `3 _LOC @x@` unter der Ereignis-`PLAC`. Er macht die
 * `_LOC`-Identität standard-portabel: erst damit zeigen INDI/FAM-Ereignisse auf den
 * Record, und webtrees/Vesta können nativ aggregieren. Der PLAC-String bleibt.
 *
 * Vier Aktionen:
 *   LINK      — es gibt Ereignisse ohne Zeiger → einfügen (Ziele in $targets).
 *   NONE      — alle passenden Ereignisse tragen den Zeiger schon (nichts zu tun).
 *   NO_LOC    — für diesen Ort existiert (noch) kein `_LOC`-Record → erst W1 laufen lassen.
 *   AMBIGUOUS — mehrere `_LOC` mit passendem Namen → User muss wählen ($candidates).
 *
 * Additiv/gap-fill: Ereignisse, die schon einen `_LOC`-Zeiger tragen, werden NIE
 * überschrieben, sondern nur gezählt ($alreadyLinked).
 */
final class LocEventLinkPlan
{
    public const ACTION_LINK      = 'link';
    public const ACTION_NONE      = 'none';
    public const ACTION_NO_LOC    = 'no_loc';
    public const ACTION_AMBIGUOUS = 'ambiguous';

    /**
     * @param list<array{xref:string,type:string,label:string,count:int}> $targets    Datensätze, die einen Zeiger bekämen (mit Anzahl Ereignisse)
     * @param list<array{xref:string,name:string}>                        $candidates bei AMBIGUOUS: die passenden `_LOC`-Records
     */
    public function __construct(
        public readonly string  $action,
        public readonly int     $placeId,
        public readonly string  $placeName,
        public readonly string  $placePath,      // voller Komma-Pfad, gegen den PLACs gematcht werden
        public readonly ?string $locXref,        // Ziel-_LOC (bei LINK/NONE gesetzt)
        public readonly array   $targets = [],
        public readonly int     $pointersToAdd = 0,
        public readonly int     $alreadyLinked = 0,
        public readonly array   $candidates = [],
    ) {}

    /** Schreibt dieser Plan tatsächlich etwas? */
    public function willWrite(): bool
    {
        return $this->action === self::ACTION_LINK && $this->pointersToAdd > 0;
    }

    /** Anzahl betroffener Datensätze (INDI/FAM), die einen Zeiger bekämen. */
    public function recordCount(): int
    {
        return count($this->targets);
    }
}
