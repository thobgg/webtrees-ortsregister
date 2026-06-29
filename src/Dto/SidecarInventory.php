<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Bestand der kuratorischen Sidecar-Schicht eines Orts — was beim Merge
 * mitwandert. Wird im Merge-Modal je Seite angezeigt, damit der User die
 * Richtung informiert wählt (datenreichere Seite = sinnvolles Ziel).
 */
final class SidecarInventory
{
    public function __construct(
        /** Anzahl Markdown-Notiz-Dateien (notes.md + custom *.md) */
        public readonly int  $markdownFiles,
        /** Anzahl strukturierter Aufgaben (_tasks.json) */
        public readonly int  $tasks,
        /** Anzahl Kirchenbuch-Einträge (_kb_list.json) */
        public readonly int  $kbs,
        /** Anzahl Digitalisate (Bilder/Dokumente, ohne Markdown/Steuerdateien) */
        public readonly int  $digitalisate,
        /** Ist der Ort mit einer GOV-ID verknüpft? */
        public readonly bool $govLinked,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, false);
    }

    public function isEmpty(): bool
    {
        return $this->curatedItems() === 0 && !$this->govLinked;
    }

    /** Summe der zählbaren kuratorischen Artefakte (ohne das GOV-Flag). */
    public function curatedItems(): int
    {
        return $this->markdownFiles + $this->tasks + $this->kbs + $this->digitalisate;
    }
}
