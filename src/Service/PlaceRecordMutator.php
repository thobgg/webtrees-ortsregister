<?php

declare(strict_types=1);

namespace Ortsregister\Service;

/**
 * DB-freie Kernlogik des Merge-Sicherheitsnetzes: PLAC-Ersetzung anwenden,
 * Undo-Restore, Stale-Erkennung. Arbeitet ausschließlich gegen einen
 * {@see RecordStore} + {@see GedcomPlaceManipulator}, damit Rollback, Undo und
 * Stale-Schutz ohne webtrees-DB integrationstestbar sind (siehe
 * PlaceRecordMutatorTest).
 *
 * Die Transaktions-Grenze (Rollback bei Abbruch) sowie Sidecar/Backup/Log
 * bleiben bewusst in PlaceOperationService – dieser Mutator kapselt nur die
 * datensatz-bezogene Logik, die sonst nirgends automatisiert prüfbar wäre.
 */
final class PlaceRecordMutator
{
    public function __construct(private readonly GedcomPlaceManipulator $manipulator) {}

    /**
     * Wendet die PLAC-Ersetzung auf alle betroffenen Records an. Schreibt nur
     * tatsächlich veränderte Datensätze. Ein Schreibfehler wird propagiert –
     * die umgebende Transaktion rollt dann alles Bisherige zurück.
     *
     * @param list<array{xref: string, type: string}> $affected
     * @param array<string, list<string>>             $targetSubtags
     * @param array<string, string>                   $resolutions
     * @return array<string, string> xref → GEDCOM nach dem Merge (nur geänderte)
     */
    public function applyMerge(
        RecordStore $store,
        array $affected,
        string $srcValue,
        string $dstValue,
        array $targetSubtags,
        array $resolutions,
    ): array {
        $afterMap = [];
        foreach ($affected as $entry) {
            $old = $store->read($entry['xref'], $entry['type']);
            if ($old === null) {
                continue;
            }
            $new = $this->manipulator->replacePlacBlock(
                $old,
                $srcValue,
                $dstValue,
                $targetSubtags,
                $resolutions,
            );
            if ($new === $old) {
                continue;
            }
            $store->write($entry['xref'], $entry['type'], $new);
            $afterMap[$entry['xref']] = $new;
        }
        return $afterMap;
    }

    /**
     * Welche betroffenen Records wurden seit der Operation verändert? Vergleicht
     * den Live-Stand kanonisch gegen den gespeicherten Nach-Operation-Stand.
     * Einträge ohne after_gedcom (Alt-Backups v1) werden übersprungen.
     *
     * @param list<array{xref: string, type: string, before_gedcom?: string, after_gedcom?: string|null}> $records
     * @return list<string> geänderte xrefs
     */
    public function detectStale(RecordStore $store, array $records): array
    {
        $changed = [];
        foreach ($records as $entry) {
            $after = $entry['after_gedcom'] ?? null;
            if ($after === null) {
                continue;
            }
            $current = $store->read($entry['xref'], $entry['type']);
            if ($current !== null
                && $this->canonical($current) !== $this->canonical((string) $after)
            ) {
                $changed[] = (string) $entry['xref'];
            }
        }
        return $changed;
    }

    /**
     * Spielt den Vor-Operation-Stand zurück. Der Aufrufer MUSS vorher
     * {@see detectStale()} prüfen und bei Änderungen abbrechen (All-or-Nothing) –
     * dieser Mutator setzt bedingungslos zurück.
     *
     * @param list<array{xref: string, type: string, before_gedcom: string}> $records
     */
    public function restore(RecordStore $store, array $records): int
    {
        $restored = 0;
        foreach ($records as $entry) {
            if ($store->read($entry['xref'], $entry['type']) === null) {
                continue;
            }
            $store->write($entry['xref'], $entry['type'], (string) $entry['before_gedcom']);
            $restored++;
        }
        return $restored;
    }

    /**
     * Normalisiert GEDCOM wie webtrees' updateRecord (Zeilenenden + trim) für
     * den stabilen Stale-Vergleich.
     */
    private function canonical(string $gedcom): string
    {
        return trim((string) preg_replace('/[\r\n]+/', "\n", $gedcom));
    }
}
