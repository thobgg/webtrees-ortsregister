<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Unveränderliches Value-Object für einen geografischen Ort
 * aus der webtrees `places`-Tabelle.
 */
final class OrtDto
{
    public function __construct(
        /** Interner webtrees-Primärschlüssel (p_id) */
        public readonly int $id,

        /** Ortsname auf der untersten Hierarchieebene (p_place) */
        public readonly string $name,

        /**
         * Vollständiger hierarchischer Pfad, z. B.
         * „Hamburg, Hamburg, Deutschland"
         */
        public readonly string $vollstaendigerPfad,

        /** Anzahl verknüpfter Einzel- und Familiendatensätze */
        public readonly int $anzahlEreignisse,

        /** Breitengrad aus place_location (null wenn keine Koordinaten) */
        public readonly float|null $breitengrad = null,

        /** Längengrad aus place_location (null wenn keine Koordinaten) */
        public readonly float|null $laengengrad = null,
    ) {}

    /**
     * Gibt den Anzeigetext für Listen und Dropdowns zurück.
     */
    public function anzeigeName(): string
    {
        return $this->vollstaendigerPfad !== ''
            ? $this->vollstaendigerPfad
            : $this->name;
    }

    /**
     * Gibt true zurück, wenn Koordinaten vorhanden sind.
     */
    public function hatKoordinaten(): bool
    {
        return $this->breitengrad !== null && $this->laengengrad !== null;
    }
}
