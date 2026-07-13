<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Gelesene Identität eines GEDCOM-L `_LOC`-Records (nur die verifizierte
 * Grammatik, siehe Memo „reference_loc_grammar_native").
 *
 * REIN LESEND — dieses Objekt beschreibt einen bereits im GEDCOM vorhandenen
 * `_LOC`-Record. Es schreibt nichts und trifft keine Semantik-Annahmen über
 * Felder, die nicht in der Grammatik verifiziert sind.
 *
 * Felder (verifizierte Grammatik):
 *   1 NAME  → $names (mehrere erlaubt; historische/befristete Varianten)
 *   1 TYPE  → $type
 *   1 MAP / 2 LATI / 2 LONG → $latitude / $longitude
 *   1 _GOV  → $govId
 *   1 _LOC @ref@ → $parentXrefs (Zeiger auf übergeordnete Orte = Hierarchie)
 */
final class LocationIdentity
{
    /**
     * @param list<string>          $names        alle `1 NAME`-Werte (Reihenfolge = GEDCOM-Reihenfolge)
     * @param list<string>          $parentXrefs  Xrefs aus `1 _LOC @ref@` (Hierarchie-Zeiger)
     * @param list<string>          $notes        inline-Freitext-Notizen (`1 NOTE`)
     * @param list<LocEvent>        $events       Ereignisse (`1 EVEN`)
     * @param list<LocDemographic>  $demographics demografische Angaben (`1 _DMGD`, z.B. Einwohnerzahlen)
     */
    public function __construct(
        public readonly string $xref,
        public readonly array $names = [],
        public readonly ?string $govId = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?string $type = null,
        public readonly array $parentXrefs = [],
        public readonly array $notes = [],
        public readonly array $events = [],
        public readonly array $demographics = [],
    ) {}

    /** Erster NAME-Wert oder leer. */
    public function primaryName(): string
    {
        return $this->names[0] ?? '';
    }

    /** Erste inline-NOTE (die Orts-Beschreibung), oder null. */
    public function primaryNote(): ?string
    {
        return $this->notes[0] ?? null;
    }

    /**
     * Alle inline-NOTEs AUSSER der ersten. Die erste NOTE ist per Modul-Konvention
     * die Orts-Beschreibung (siehe PlaceDescriptionService/LocationWriter::setInlineNote)
     * und wird schon in ihrer eigenen Karte gezeigt — hiermit vermeidet die
     * _LOC-Inhalts-Anzeige (Issue #12) die Doppelung bei gebundenen Records.
     *
     * @return list<string>
     */
    public function secondaryNotes(): array
    {
        return array_slice($this->notes, 1);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function hasGov(): bool
    {
        return $this->govId !== null && $this->govId !== '';
    }

    /**
     * true, wenn der Record ausser dem XREF keinerlei auswertbare Identität trägt
     * (leerer Rumpf-Record `0 @X@ _LOC`).
     */
    public function isEmpty(): bool
    {
        return $this->names === []
            && $this->govId === null
            && $this->latitude === null
            && $this->longitude === null
            && $this->type === null
            && $this->parentXrefs === [];
    }
}
