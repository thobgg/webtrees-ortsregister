<?php

declare(strict_types=1);

namespace Ortsregister\Service;

/**
 * Schmale Naht über den Datensatz-Zugriff, damit die Merge-/Undo-Kernlogik
 * (siehe {@see PlaceRecordMutator}) ohne webtrees-DB integrationstestbar ist.
 *
 * Produktion: {@see WebtreesRecordStore} (liest/schreibt echte GedcomRecords).
 * Test:       In-Memory-Fake.
 */
interface RecordStore
{
    /** Aktuelles GEDCOM des Datensatzes – oder null, wenn er nicht (mehr) existiert. */
    public function read(string $xref, string $type): ?string;

    /**
     * Schreibt neues GEDCOM. Fehler werden als Exception propagiert (nicht
     * geschluckt), damit die umgebende DB-Transaktion zurückrollen kann.
     */
    public function write(string $xref, string $type, string $gedcom): void;
}
