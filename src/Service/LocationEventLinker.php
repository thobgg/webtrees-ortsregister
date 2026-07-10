<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\LocEventLinkPlan;
use Ortsregister\Dto\LocationIdentity;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use RuntimeException;

/**
 * W2 der `_LOC`-Identitäts-Schicht: setzt den Ereignis→Ort-Zeiger `<n+1> _LOC @x@`
 * unter die Ereignis-`PLAC` betroffener INDI/FAM — additiv, gap-fill, opt-in.
 *
 * Erst dieser Zeiger macht die Identität standard-portabel: die Ereignisse zeigen
 * auf den einen `_LOC`-Record, webtrees/Vesta können nativ aggregieren. Der
 * PLAC-String bleibt unangetastet.
 *
 * Grammatik am Core verifiziert (`app/CustomTags/GedcomL.php`): `INDI:*:PLAC:_LOC`
 * und `FAM:*:PLAC:_LOC` sind native `XrefLocation`-Tags. Der Import trackt den
 * Pointer NICHT separat (keine placelinks-Pflege nötig, nur der PLAC-String zählt,
 * und der bleibt gleich) — der native `updateRecord()` reicht.
 *
 * Der Chirurgie-Kern (`addLocPointer`) ist rein und isoliert testbar; `plan()`/
 * `execute()`/`undo()` sprechen DB + native Core-API.
 */
final class LocationEventLinker
{
    public function __construct(
        private readonly LocationReader  $reader,
        private readonly OperationBackup $backup,
    ) {}

    // ---------------------------------------------------------------
    // Plan (was würde geschrieben)
    // ---------------------------------------------------------------

    /**
     * Ermittelt, welche Ereignisse am Ort einen `_LOC`-Zeiger bekämen.
     * $placePath = voller Komma-Pfad des Orts (gegen den PLACs gematcht werden).
     * $targetXref (optional) = der GEBUNDENE _LOC des Orts (Binding-first, Loch 4) —
     * dann entfällt der Namens-Match, der bei gleichnamigen Orten falsch greifen kann.
     */
    public function plan(Tree $tree, int $placeId, string $leaf, string $placePath, ?string $targetXref = null): LocEventLinkPlan
    {
        if ($targetXref !== null && $targetXref !== '') {
            $bound    = $this->reader->make($tree, $targetXref);
            $existing = $bound !== null ? [$bound] : [];
        } else {
            $existing = $this->reader->forPlaceName($tree, $leaf);
        }

        if (count($existing) > 1) {
            $candidates = array_map(
                static fn(LocationIdentity $e): array => ['xref' => $e->xref, 'name' => $e->primaryName()],
                $existing,
            );
            return new LocEventLinkPlan(LocEventLinkPlan::ACTION_AMBIGUOUS, $placeId, $leaf, $placePath, null, [], 0, 0, array_values($candidates));
        }
        if ($existing === []) {
            return new LocEventLinkPlan(LocEventLinkPlan::ACTION_NO_LOC, $placeId, $leaf, $placePath, null);
        }

        $locXref = $existing[0]->xref;

        $targets       = [];
        $pointersToAdd = 0;
        $alreadyLinked = 0;

        foreach ($this->recordsAtPlace($tree, $placeId) as $record) {
            [, $add, $linked] = $this->addLocPointer($record->gedcom(), $placePath, $locXref);
            $alreadyLinked += $linked;
            if ($add > 0) {
                $pointersToAdd += $add;
                $targets[] = [
                    'xref'  => $record->xref(),
                    'type'  => $record instanceof Family ? 'FAM' : ($record instanceof Individual ? 'INDI' : 'OTHER'),
                    'label' => strip_tags($record->fullName()),
                    'count' => $add,
                ];
            }
        }

        $action = $pointersToAdd > 0 ? LocEventLinkPlan::ACTION_LINK : LocEventLinkPlan::ACTION_NONE;
        return new LocEventLinkPlan($action, $placeId, $leaf, $placePath, $locXref, $targets, $pointersToAdd, $alreadyLinked);
    }

    // ---------------------------------------------------------------
    // Ausführen (native Core-API) + Backup
    // ---------------------------------------------------------------

    /**
     * Plant serverseitig neu und schreibt die Zeiger. Liefert
     * {written, records, pointers, xref, backup_path}.
     *
     * @return array{written:bool, records:int, pointers:int, xref:?string, backup_path:?string}
     */
    public function execute(Tree $tree, int $placeId, string $leaf, string $placePath, ?string $targetXref = null): array
    {
        $plan = $this->plan($tree, $placeId, $leaf, $placePath, $targetXref);
        if (!$plan->willWrite()) {
            return ['written' => false, 'records' => 0, 'pointers' => 0, 'xref' => $plan->locXref, 'backup_path' => null];
        }
        $this->assertAutoAccept();

        $xref     = (string) $plan->locXref;
        $affected = [];
        $pointers = 0;

        foreach ($this->recordsAtPlace($tree, $placeId) as $record) {
            $pre               = $record->gedcom();
            [$new, $add,]      = $this->addLocPointer($pre, $placePath, $xref);
            if ($add > 0 && $new !== $pre) {
                $record->updateRecord($new, true);
                $affected[] = [
                    'xref' => $record->xref(),
                    'type' => $record instanceof Family ? 'FAM' : 'INDI',
                    'pre'  => $pre,
                    'post' => $record->gedcom(), // nach Re-Import (kanonische Form) — Basis für den Undo-Vergleich
                ];
                $pointers += $add;
            }
        }

        $payload = [
            'version'    => 1,
            'operation'  => 'loc_event_link',
            'place_id'   => $placeId,
            'place_name' => $leaf,
            'loc_xref'   => $xref,
            'records'    => $affected,
        ];
        $backupPath = $this->backup->write('locev_' . $leaf, $payload);

        return ['written' => true, 'records' => count($affected), 'pointers' => $pointers, 'xref' => $xref, 'backup_path' => $backupPath];
    }

    /**
     * Macht einen früheren `execute()` rückgängig: stellt je Datensatz den
     * Vor-Stand wieder her. Ein Datensatz, der sich seither geändert hat (aktueller
     * Stand ≠ geschriebener Stand), wird ÜBERSPRUNGEN (nie spätere Edits überschreiben).
     *
     * @return array{reverted:int, skipped:list<string>}
     */
    public function undo(Tree $tree, string $backupPath): array
    {
        $b       = $this->backup->read($backupPath);
        $records = is_array($b['records'] ?? null) ? $b['records'] : [];

        $reverted = 0;
        $skipped  = [];
        foreach ($records as $r) {
            $xref = (string) ($r['xref'] ?? '');
            $pre  = (string) ($r['pre'] ?? '');
            $post = (string) ($r['post'] ?? '');
            if ($xref === '') {
                continue;
            }
            $record = Registry::gedcomRecordFactory()->make($xref, $tree);
            if ($record === null) {
                $skipped[] = $xref;
                continue;
            }
            if ($this->normalizeForCompare($record->gedcom()) !== $this->normalizeForCompare($post)) {
                $skipped[] = $xref; // seit dem Schreiben verändert → nicht anfassen
                continue;
            }
            $record->updateRecord($pre, false);
            $reverted++;
        }

        return ['reverted' => $reverted, 'skipped' => $skipped];
    }

    // ---------------------------------------------------------------
    // Reiner Chirurgie-Kern (isoliert testbar)
    // ---------------------------------------------------------------

    /**
     * Fügt `<L+1> _LOC @xref@` unter jede passende `<L> PLAC <pfad>` ein, die noch
     * KEINEN `_LOC`-Subzeiger trägt. Ändert sonst nichts, erhält Reihenfolge/Inhalt.
     * Idempotent (ein bereits gesetzter Zeiger — egal welcher — wird respektiert).
     *
     * @return array{0:string, 1:int, 2:int}  [neuesGedcom, eingefügt, bereitsVerknüpft]
     */
    public function addLocPointer(string $gedcom, string $placePath, string $locXref): array
    {
        $want = $this->canonicalPlac($placePath);
        $xref = trim($locXref);
        if ($want === '' || $xref === '') {
            return [$gedcom, 0, 0];
        }

        $lines   = explode("\n", $gedcom);
        $n       = count($lines);
        $out     = [];
        $added   = 0;
        $linked  = 0;

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            $out[] = $line;

            if (!preg_match('/^(\d+) PLAC (.*)$/', rtrim($line, "\r"), $m)) {
                continue;
            }
            if ($this->canonicalPlac($m[2]) !== $want) {
                continue;
            }
            $level = (int) $m[1];

            // PLAC-Sub-Block nach einem vorhandenen (level+1) _LOC absuchen.
            $hasPointer = false;
            for ($j = $i + 1; $j < $n; $j++) {
                if (!preg_match('/^(\d+)/', rtrim($lines[$j], "\r"), $lm)) {
                    break;
                }
                $l = (int) $lm[1];
                if ($l <= $level) {
                    break; // PLAC-Block endet
                }
                if ($l === $level + 1 && preg_match('/^\d+ _LOC @/', rtrim($lines[$j], "\r"))) {
                    $hasPointer = true;
                    break;
                }
            }

            if ($hasPointer) {
                $linked++;
            } else {
                $out[] = ($level + 1) . ' _LOC @' . $xref . '@';
                $added++;
            }
        }

        return [implode("\n", $out), $added, $linked];
    }

    // ---------------------------------------------------------------
    // Interne Helfer
    // ---------------------------------------------------------------

    /**
     * INDI/FAM-Datensätze mit einem Ereignis an genau diesem Ort (über placelinks).
     * placelinks bindet pro Ort-Ebene — pl_p_id = dieser Ort filtert auf die Ereignisse
     * an genau dieser Hierarchie-Ebene. Der PLAC-String-Match in addLocPointer schärft
     * dann auf das exakte Fact.
     *
     * @return list<GedcomRecord>
     */
    private function recordsAtPlace(Tree $tree, int $placeId): array
    {
        $xrefs = DB::table('placelinks')
            ->where('pl_file',  '=', $tree->id())
            ->where('pl_p_id',  '=', $placeId)
            ->pluck('pl_gid');

        $records = [];
        foreach ($xrefs as $xref) {
            $record = Registry::gedcomRecordFactory()->make((string) $xref, $tree);
            if ($record instanceof Individual || $record instanceof Family) {
                $records[] = $record;
            }
        }
        return $records;
    }

    /** Kanonischer PLAC-Vergleich: Komponenten an Kommas trimmen (Spacing-tolerant). */
    private function canonicalPlac(string $s): string
    {
        $parts = array_map('trim', explode(',', trim(rtrim($s, "\r"))));
        return implode(',', $parts);
    }

    /**
     * Vergleichs-Normalisierung fürs Undo: CHAN-Block (Zeitstempel) raus, Zeilen
     * rechts-trimmen, damit ein Re-Import (der CHAN aktualisiert) nicht als „geändert" gilt.
     */
    private function normalizeForCompare(string $gedcom): string
    {
        $lines = explode("\n", $gedcom);
        $out   = [];
        $skip  = false;
        foreach ($lines as $line) {
            $line = rtrim($line, "\r ");
            if (preg_match('/^1 CHAN\b/', $line)) {
                $skip = true;
                continue;
            }
            if ($skip) {
                // CHAN-Unterzeilen (Level >= 2) überspringen; bei Level <= 1 endet der Block.
                if (preg_match('/^([01]) /', $line)) {
                    $skip = false;
                } else {
                    continue;
                }
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    private function assertAutoAccept(): void
    {
        if (Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) !== '1') {
            throw new RuntimeException(
                'Zum Setzen der _LOC-Ereignis-Zeiger muss in deinen Kontoeinstellungen '
                . '„Änderungen automatisch übernehmen" aktiv sein — sonst blieben die Änderungen in der Moderation hängen.'
            );
        }
    }
}
