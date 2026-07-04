<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Dto\MergeAnalysis;
use Ortsregister\Dto\MergeResult;
use Ortsregister\Dto\SubtagConflict;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use RuntimeException;

/**
 * Kernservice für Place-Operations (Merge, später Rename/GOV-Linking/Geo).
 *
 * Architektur-Konsens „GEDCOM persistent" (Modell A):
 * Operationen schreiben GEDCOM-Strings via GedcomRecord::updateRecord().
 * webtrees-Core re-derived placelinks/places/etc. automatisch.
 * Keine direkten Schreibzugriffe auf Index-Tabellen.
 *
 * Verwaiste Place-Records werden vom Core in GedcomImportService::updateRecord()
 * automatisch aufgeräumt (do-while-Loop in webtrees Z.810-819). Entscheidung 2A
 * ist damit gratis.
 *
 * Pending-Changes-Bypass via PREF_AUTO_ACCEPT_EDITS-Check (Entscheidung 1A):
 * Wenn nicht aktiv → Exception, User soll Pref aktivieren.
 *
 * Backup-Format v1: JSON pro Operation mit kompletten GEDCOM-Strings der
 * betroffenen Records vor der Operation. Selbstständig — auch ohne Modul
 * wiederherstellbar.
 */
class PlaceOperationService
{
    // v2: Backup enthält zusätzlich die Sidecar-Sektionen (place_meta + Ordner-Manifest).
    private const BACKUP_VERSION = 2;

    /** Ab so vielen betroffenen Records warnt analyzeMerge vor dem Single-Transaction-Merge. */
    private const LARGE_MERGE_WARN = 500;

    public function __construct(
        private readonly ApcuCacheService        $cache,
        private readonly GedcomPlaceManipulator  $manipulator,
        private readonly string                  $backupDir,
        private readonly PlaceSidecarMerger      $sidecarMerger,
        private readonly PlaceSidecarInventory   $inventory,
        private readonly PlaceRecordMutator      $mutator,
    ) {}

    // ---------------------------------------------------------------
    // Öffentliche API
    // ---------------------------------------------------------------

    /**
     * Pre-Flight: was würde der Merge bewirken?
     * Keine Schreib-Operation. Sicher mehrfach aufrufbar.
     */
    public function analyzeMerge(Tree $tree, int $srcId, int $dstId): MergeAnalysis
    {
        [$srcValue, $dstValue] = $this->loadPlaceValues($tree, $srcId, $dstId);

        $affected = $this->findAffectedRecords($tree, $srcId);
        $counts   = $this->countByType($affected);

        // Subtag-Diff: aus EINEM Beispiel-Record je Subtag rausziehen.
        // Bei opaker Strategie reicht die erste Quell-Vorkommnis als Basis.
        $sourceSubtags = [];
        $targetSubtags = [];
        if ($affected !== []) {
            $first = $affected[0];
            $rec   = $this->loadRecord($tree, $first['xref'], $first['type']);
            if ($rec !== null) {
                $sourceSubtags = $this->manipulator->extractDirectSubtags($rec->gedcom(), $srcValue);
            }
        }
        // Ziel-Subtags aus einem Record holen der den Ziel-Place nutzt
        $targetUsers = $this->findAffectedRecords($tree, $dstId);
        if ($targetUsers !== []) {
            $first = $targetUsers[0];
            $rec   = $this->loadRecord($tree, $first['xref'], $first['type']);
            if ($rec !== null) {
                $targetSubtags = $this->manipulator->extractDirectSubtags($rec->gedcom(), $dstValue);
            }
        }

        $conflicts = $this->manipulator->detectConflicts($sourceSubtags, $targetSubtags);

        $warnings = [];
        if (isset($sourceSubtags['_LOC']) || isset($targetSubtags['_LOC'])) {
            $warnings[] = 'Beteiligte PLACs verweisen auf Vesta-_LOC-Records. '
                . 'Mit dem Merge können _LOC-Records verwaisen — separate Aufräum-Operation später.';
        }

        // Großer Merge: läuft in EINER Transaktion ohne Batching (Härtung #1
        // offen) → bei sehr vielen Records droht Timeout/Speicher + Riesen-Backup.
        if (count($affected) > self::LARGE_MERGE_WARN) {
            $warnings[] = sprintf(
                'Großer Merge: %d betroffene Datensätze. Das läuft in einer einzigen Transaktion '
                . '(kein Batching) — bei sehr großen Orten droht Timeout/Speicher. Vorher den Baum '
                . 'sichern; im Zweifel an einem kleineren Ort testen.',
                count($affected),
            );
        }

        // Degenerierter Merge: Quelle und Ziel unterscheiden sich nur durch
        // Satzzeichen/Whitespace/Groß-/Kleinschreibung (z.B. "X" vs "X.").
        // Das ist fast immer ein Tippfehler — ein Rename wäre richtig, nicht ein
        // Merge (der einen Phantom-Ort als Ziel zementiert).
        if ($this->nearIdentical($srcValue, $dstValue)) {
            $warnings[] = sprintf(
                'Quelle „%s" und Ziel „%s" unterscheiden sich nur durch Schreibweise/Satzzeichen — '
                . 'das ist fast immer ein Tippfehler. Achte darauf, die KORREKTE Schreibweise als Ziel '
                . '(gewinnt) zu wählen. Ein reines Umbenennen wäre hier sauberer als ein Merge.',
                $srcValue,
                $dstValue,
            );
        }

        // Richtungs-Heuristik (rein mechanisch, keine Ortsbedeutung): Ziel mit
        // weniger Hierarchie-Ebenen als die Quelle → der Merge entfernt diese
        // Detailtiefe von den betroffenen Ereignissen.
        if (substr_count($dstValue, ',') < substr_count($srcValue, ',')) {
            $warnings[] = sprintf(
                'Das Ziel „%s" hat weniger Hierarchie-Ebenen als die Quelle „%s" — beim Merge verlieren '
                . 'die betroffenen Ereignisse diese Detailtiefe. Bitte prüfen, ob die Richtung stimmt.',
                $dstValue,
                $srcValue,
            );
        }

        // Kuratorischer Bestand beider Seiten — für die Richtungs-Entscheidung.
        $sourceSidecar = $this->inventory->forPlace($tree, $srcId, $this->leafName($tree, $srcId));
        $targetSidecar = $this->inventory->forPlace($tree, $dstId, $this->leafName($tree, $dstId));

        return new MergeAnalysis(
            sourcePlaceId:    $srcId,
            targetPlaceId:    $dstId,
            sourceValue:      $srcValue,
            targetValue:      $dstValue,
            affectedCounts:   $counts,
            conflicts:        $conflicts,
            warnings:         $warnings,
            sourceSidecar:    $sourceSidecar,
            targetSidecar:    $targetSidecar,
        );
    }

    /**
     * Führt den Merge durch.
     *
     * @param array<string, string> $resolutions  Tag → 'source'|'target'|'drop'
     */
    public function executeMerge(
        Tree  $tree,
        int   $srcId,
        int   $dstId,
        array $resolutions = [],
    ): MergeResult {
        $this->assertAutoAcceptEdits();

        [$srcValue, $dstValue] = $this->loadPlaceValues($tree, $srcId, $dstId);
        if ($srcValue === $dstValue) {
            throw new RuntimeException('Quelle und Ziel haben denselben PLAC-Wert — nichts zu mergen.');
        }

        $affected = $this->findAffectedRecords($tree, $srcId);
        if ($affected === []) {
            throw new RuntimeException('Quell-Place hat keine verlinkten Records.');
        }

        // Ziel-Subtags einmal holen (für Merge-Strategie)
        $targetSubtags = [];
        $targetUsers   = $this->findAffectedRecords($tree, $dstId);
        if ($targetUsers !== []) {
            $first = $targetUsers[0];
            $rec   = $this->loadRecord($tree, $first['xref'], $first['type']);
            if ($rec !== null) {
                $targetSubtags = $this->manipulator->extractDirectSubtags($rec->gedcom(), $dstValue);
            }
        }

        // Sidecar-Vorbedingungen VOR der Mutation erfassen — places/place_id
        // ändern sich danach durch das Core-Reindexing.
        $srcLeaf          = $this->leafName($tree, $srcId);
        $dstLeaf          = $this->leafName($tree, $dstId);
        $srcLeafAmbiguous = $this->leafAmbiguous($tree, $srcLeaf);
        $dstLeafAmbiguous = $this->leafAmbiguous($tree, $dstLeaf);
        $coordWarning     = ($this->hasCoordinates($srcLeaf) && !$this->hasCoordinates($dstLeaf))
            ? sprintf(
                'Die Quelle „%s" hatte Koordinaten, das Ziel „%s" nicht — sie werden nicht '
                . 'übernommen. Bitte am Ziel neu setzen.',
                $srcLeaf,
                $dstLeaf,
            )
            : null;

        $result = DB::connection()->transaction(function () use (
            $tree, $srcId, $dstId, $srcValue, $dstValue,
            $affected, $targetSubtags, $resolutions,
            $srcLeaf, $dstLeaf, $srcLeafAmbiguous, $dstLeafAmbiguous, $coordWarning
        ): MergeResult {
            $store = new WebtreesRecordStore($tree);

            // Backup einsammeln (BEFORE-Snapshot der GEDCOM-Strings)
            $backup = $this->buildBackup($tree, $srcId, $dstId, $srcValue, $dstValue, $affected);

            // Modifikationen anwenden — DB-freie Kernlogik im PlaceRecordMutator.
            // Wirft bei Schreibfehler → diese Transaktion rollt alles zurück.
            // afterMap: xref → Nach-Merge-GEDCOM (für Undo-Stale-Check).
            $afterMap = $this->mutator->applyMerge(
                $store,
                $affected,
                $srcValue,
                $dstValue,
                $targetSubtags,
                $resolutions,
            );
            $modified = count($afterMap);

            // Nach-Merge-Stand ins Backup — Undo prüft damit, ob ein Record
            // seither verändert wurde (Schutz vor Überschreiben fremder Edits).
            $this->annotateBackupAfterState($backup, $afterMap);

            // Kuratorische Schicht mit-mergen (DB place_meta + Sidecar-Ordner).
            // Schreibt die Vorher-Snapshots in die Backup-Sektionen für Undo.
            $sidecar = $this->sidecarMerger->apply(
                $tree,
                $srcId,
                $dstId,
                $srcLeaf,
                $dstLeaf,
                $srcLeafAmbiguous,
                $dstLeafAmbiguous,
            );
            $backup['sections']['place_meta'] = $sidecar['place_meta'];
            $backup['sections']['folders']    = $sidecar['folders'];

            $warnings = $sidecar['warnings'];
            if ($coordWarning !== null) {
                $warnings[] = $coordWarning;
            }

            // Backup persistieren + Log
            $backupPath = $this->writeBackup($backup);
            $logId      = $this->insertLogEntry($tree, 'merge', $srcId, $dstId, $backupPath);

            return new MergeResult(
                sourcePlaceId:   $srcId,
                targetPlaceId:   $dstId,
                modifiedRecords: $modified,
                backupPath:      $backupPath,
                logId:           $logId,
                warnings:        $warnings,
            );
        });

        $this->cache->flush();
        return $result;
    }

    /**
     * Leichte Vorschau fürs Rename-Modal: aktueller voller PLAC-Wert (für
     * korrektes Pre-Fill) + Anzahl betroffener Records.
     *
     * @return array{fullName: string, affectedCount: int}
     */
    public function analyzeRename(Tree $tree, int $srcId): array
    {
        return [
            'fullName'      => $this->fullPlaceName($tree, $srcId),
            'affectedCount' => count($this->findAffectedRecords($tree, $srcId)),
        ];
    }

    /**
     * Benennt einen Ort um: ersetzt seinen vollen PLAC-Wert durch einen NEUEN
     * (noch nicht existierenden) Wert über ALLE seine Records. Reine Hygiene-Op
     * ohne Ziel-Ort — der einfachste Fall der Sidecar-Move-Schicht (der Ordner
     * wird komplett umbenannt). Reuse der GATE-Maschinerie (Backup-v2, Undo,
     * Leaf-Wächter). Undo läuft über denselben undoMerge-Pfad.
     *
     * Existiert der neue Name bereits als Ort → das wäre ein Merge, kein Rename
     * → Exception (zur Merge-Route lenken).
     */
    public function executeRename(Tree $tree, int $srcId, string $newValue): MergeResult
    {
        $this->assertAutoAcceptEdits();

        $newValue = trim($newValue);
        if ($newValue === '') {
            throw new RuntimeException('Der neue Name darf nicht leer sein.');
        }

        $srcValue = $this->fullPlaceName($tree, $srcId);
        if ($srcValue === '') {
            throw new RuntimeException('Quell-Place nicht gefunden.');
        }
        if ($srcValue === $newValue) {
            throw new RuntimeException('Der neue Name ist mit dem alten identisch — nichts zu tun.');
        }
        if ($this->placeIdByFullName($tree, $newValue) !== null) {
            throw new RuntimeException(sprintf(
                'Ein Ort „%s" existiert bereits — bitte zusammenführen (Merge) statt umbenennen.',
                $newValue,
            ));
        }

        $affected = $this->findAffectedRecords($tree, $srcId);
        if ($affected === []) {
            throw new RuntimeException('Quell-Place hat keine verlinkten Records.');
        }

        // Sidecar-Vorbedingungen VOR der Mutation erfassen.
        $srcLeaf          = $this->leafName($tree, $srcId);
        $newLeaf          = explode(', ', $newValue)[0];
        $srcLeafAmbiguous = $this->leafAmbiguous($tree, $srcLeaf);
        $newLeafCollides  = $this->leafExists($tree, $newLeaf); // Interims-Wächter (Memo Z.75)

        $result = DB::connection()->transaction(function () use (
            $tree, $srcId, $srcValue, $newValue, $affected,
            $srcLeaf, $newLeaf, $srcLeafAmbiguous, $newLeafCollides
        ): MergeResult {
            // dst_value = newValue für korrektes Undo (Namens-Auflösung).
            $backup = $this->buildBackup($tree, $srcId, $srcId, $srcValue, $newValue, $affected);
            $backup['operation'] = 'rename';

            $modified = 0;
            $afterMap = [];
            foreach ($affected as $entry) {
                $record = $this->loadRecord($tree, $entry['xref'], $entry['type']);
                if ($record === null) {
                    continue;
                }
                $oldGedcom = $record->gedcom();
                $newGedcom = $this->manipulator->replacePlacBlock($oldGedcom, $srcValue, $newValue, [], []);
                if ($newGedcom === $oldGedcom) {
                    continue;
                }
                $record->updateRecord($newGedcom, false);
                $afterMap[$entry['xref']] = $newGedcom;
                $modified++;
            }
            $this->annotateBackupAfterState($backup, $afterMap);

            // Neue place_id steht erst NACH dem Rewrite fest (Reindex).
            $newId = $this->placeIdByFullName($tree, $newValue);

            $warnings = [];
            if ($newId !== null) {
                // Sidecar (Ordner + place_meta) vom alten an den neuen Namen ziehen.
                // Ziel hat weder Ordner noch place_meta → reiner Ordner-rename + Row-Umhängen.
                $sidecar = $this->sidecarMerger->apply(
                    $tree, $srcId, $newId, $srcLeaf, $newLeaf,
                    $srcLeafAmbiguous, $newLeafCollides,
                );
                $backup['sections']['place_meta'] = $sidecar['place_meta'];
                $backup['sections']['folders']    = $sidecar['folders'];
                $warnings = $sidecar['warnings'];
            } else {
                $warnings[] = 'Der umbenannte Ort konnte nicht aufgelöst werden — kuratierte Daten '
                    . 'wurden nicht verschoben, bitte manuell prüfen.';
            }

            $backupPath = $this->writeBackup($backup);
            $logId      = $this->insertLogEntry($tree, 'rename', $srcId, $newId ?? $srcId, $backupPath);

            return new MergeResult(
                sourcePlaceId:   $srcId,
                targetPlaceId:   $newId ?? $srcId,
                modifiedRecords: $modified,
                backupPath:      $backupPath,
                logId:           $logId,
                warnings:        $warnings,
            );
        });

        $this->cache->flush();
        return $result;
    }

    /**
     * Rollback eines früheren Merges. Spielt die GEDCOM-Strings aus dem
     * Backup zurück. webtrees-Core re-derived Index-Tabellen automatisch.
     */
    public function undoMerge(Tree $tree, string $backupPath): int
    {
        $this->assertAutoAcceptEdits();

        $backup  = $this->readBackup($backupPath);
        $version = (int) ($backup['version'] ?? 0);
        if ($version < 1 || $version > self::BACKUP_VERSION) {
            throw new RuntimeException('Unbekannte Backup-Version: ' . ($backup['version'] ?? '?'));
        }

        /** @var list<array{xref: string, type: string, before_gedcom: string, after_gedcom?: string}> $gedcomSection */
        $gedcomSection = $backup['sections']['gedcom']['affected_records'] ?? [];
        $metaSection   = $backup['sections']['place_meta'] ?? null;
        $folderSection = $backup['sections']['folders'] ?? null;

        $store = new WebtreesRecordStore($tree);

        // Stale-Schutz (Härtungspunkt #2): wurde ein betroffener Record seit der
        // Operation verändert? Dann würde der Restore fremde Edits überschreiben
        // → ALL-OR-NOTHING-Abbruch, NICHTS wird angefasst. (Alte Backups ohne
        // after_gedcom überspringen die Prüfung — kein Schutz, aber kein Fehler.)
        $changed = $this->mutator->detectStale($store, $gedcomSection);
        if ($changed !== []) {
            throw new RuntimeException(sprintf(
                'Rückgängig abgebrochen: %d Datensatz/Datensätze wurde(n) seit der Operation '
                . 'geändert (%s) — ein Zurücksetzen würde diese Änderungen überschreiben. '
                . 'Es wurde NICHTS verändert. Bei Bedarf manuell aus dem Backup wiederherstellen.',
                count($changed),
                implode(', ', array_slice($changed, 0, 5)) . (count($changed) > 5 ? ' …' : ''),
            ));
        }

        $restored = 0;

        DB::connection()->transaction(function () use ($store, $gedcomSection, &$restored): void {
            $restored = $this->mutator->restore($store, $gedcomSection);
        });

        // Aktuelle place_id von Quelle/Ziel nach dem GEDCOM-Restore auflösen
        // (Härtungspunkt #7: place_id driftet nach Reindex → nach NAME auflösen).
        $srcIdNow = $this->placeIdByFullName($tree, (string) ($backup['src_value'] ?? ''));
        $dstIdNow = $this->placeIdByFullName($tree, (string) ($backup['dst_value'] ?? ''));

        // Sidecar zurückspielen (place_meta + Ordner). Nur v2-Backups haben das.
        $this->sidecarMerger->restore(
            $tree,
            is_array($metaSection) ? $metaSection : null,
            is_array($folderSection) ? $folderSection : null,
            $srcIdNow,
            $dstIdNow,
        );

        $this->cache->flush();
        return $restored;
    }

    // ---------------------------------------------------------------
    // Intern: Lade-Helfer
    // ---------------------------------------------------------------

    /** @return array{0: string, 1: string} [srcValue, dstValue] — volle Komma-Pfade */
    private function loadPlaceValues(Tree $tree, int $srcId, int $dstId): array
    {
        $src = $this->fullPlaceName($tree, $srcId);
        $dst = $this->fullPlaceName($tree, $dstId);
        if ($src === '' || $dst === '') {
            throw new RuntimeException('Quell- oder Ziel-Place nicht gefunden.');
        }
        return [$src, $dst];
    }

    /**
     * Baut den vollen Komma-Pfad eines Place-Records via rekursivem
     * p_parent_id-Walk. webtrees speichert jede Hierarchie-Ebene als
     * eigenen Record; PLAC im GEDCOM enthaelt aber den vollen Pfad.
     */
    private function fullPlaceName(Tree $tree, int $placeId): string
    {
        $parts     = [];
        $currentId = $placeId;
        $seen      = [];   // Schutz vor zyklischen Verweisen

        while ($currentId > 0 && !isset($seen[$currentId])) {
            $seen[$currentId] = true;
            $row = DB::table('places')
                ->where('p_id', '=', $currentId)
                ->where('p_file', '=', $tree->id())
                ->select(['p_place', 'p_parent_id'])
                ->first();
            if ($row === null) {
                break;
            }
            $parts[]   = (string) $row->p_place;
            $currentId = (int) $row->p_parent_id;
        }
        return implode(', ', $parts);
    }

    /**
     * Alle Records (INDI/FAM/SOUR/OBJE/sonstige) die diesen Place referenzieren.
     *
     * @return list<array{xref: string, type: string}>
     */
    private function findAffectedRecords(Tree $tree, int $placeId): array
    {
        $rows = DB::table('placelinks')
            ->where('pl_p_id', '=', $placeId)
            ->where('pl_file', '=', $tree->id())
            ->select(['pl_gid'])
            ->distinct()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $xref = (string) $row->pl_gid;
            $type = $this->detectRecordType($tree, $xref);
            if ($type !== null) {
                $out[] = ['xref' => $xref, 'type' => $type];
            }
        }
        return $out;
    }

    /**
     * Versucht den Record-Typ aus der DB zu erkennen.
     * Reihenfolge: INDI → FAM → SOUR → OBJE → andere.
     */
    private function detectRecordType(Tree $tree, string $xref): ?string
    {
        $checks = [
            'INDI' => ['individuals', 'i_id', 'i_file'],
            'FAM'  => ['families',    'f_id', 'f_file'],
            'SOUR' => ['sources',     's_id', 's_file'],
            'OBJE' => ['media',       'm_id', 'm_file'],
        ];
        foreach ($checks as $type => [$table, $idCol, $fileCol]) {
            $exists = DB::table($table)
                ->where($idCol, '=', $xref)
                ->where($fileCol, '=', $tree->id())
                ->exists();
            if ($exists) {
                return $type;
            }
        }
        // Fallback: 'other'-Tabelle
        $row = DB::table('other')
            ->where('o_id', '=', $xref)
            ->where('o_file', '=', $tree->id())
            ->select('o_type')
            ->first();
        return $row !== null ? (string) $row->o_type : null;
    }

    private function loadRecord(Tree $tree, string $xref, string $type): ?GedcomRecord
    {
        return match ($type) {
            'INDI' => Registry::individualFactory()->make($xref, $tree),
            'FAM'  => Registry::familyFactory()->make($xref, $tree),
            'SOUR' => Registry::sourceFactory()->make($xref, $tree),
            'OBJE' => Registry::mediaFactory()->make($xref, $tree),
            default => Registry::gedcomRecordFactory()->make($xref, $tree),
        };
    }

    /**
     * @param list<array{xref: string, type: string}> $affected
     * @return array<string, int>
     */
    private function countByType(array $affected): array
    {
        $c = [];
        foreach ($affected as $e) {
            $c[$e['type']] = ($c[$e['type']] ?? 0) + 1;
        }
        return $c;
    }

    private function assertAutoAcceptEdits(): void
    {
        if (Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) !== '1') {
            throw new RuntimeException(
                'Ortsregister-Operationen erfordern aktivierte User-Einstellung '
                . '„Änderungen automatisch übernehmen" (PREF_AUTO_ACCEPT_EDITS). '
                . 'Bitte in den Benutzereinstellungen aktivieren.',
            );
        }
    }

    // ---------------------------------------------------------------
    // Backup
    // ---------------------------------------------------------------

    /**
     * @param list<array{xref: string, type: string}> $affected
     * @return array<string, mixed>
     */
    private function buildBackup(
        Tree   $tree,
        int    $srcId,
        int    $dstId,
        string $srcValue,
        string $dstValue,
        array  $affected,
    ): array {
        $records = [];
        foreach ($affected as $e) {
            $rec = $this->loadRecord($tree, $e['xref'], $e['type']);
            if ($rec === null) {
                continue;
            }
            $records[] = [
                'xref'          => $e['xref'],
                'type'          => $e['type'],
                'before_gedcom' => $rec->gedcom(),
            ];
        }

        return [
            'version'      => self::BACKUP_VERSION,
            'operation'    => 'merge',
            'timestamp'    => date('c'),
            'tree_id'      => $tree->id(),
            'user_id'      => Auth::id(),
            'src_place_id' => $srcId,
            'dst_place_id' => $dstId,
            'src_value'    => $srcValue,
            'dst_value'    => $dstValue,
            'sections'     => [
                'gedcom' => [
                    'affected_records' => $records,
                ],
                // Phase 4: 'place_meta' kommt hierher
            ],
        ];
    }

    /**
     * Trägt den Nach-Operation-GEDCOM-Stand je betroffenem Record ins Backup.
     * Undo vergleicht damit gegen den Live-Stand und bricht ab, falls ein Record
     * seither verändert wurde (Schutz vor Überschreiben fremder Edits, #2).
     *
     * @param array<string, mixed>  $backup
     * @param array<string, string> $afterMap xref → GEDCOM nach der Operation
     */
    private function annotateBackupAfterState(array &$backup, array $afterMap): void
    {
        if (!isset($backup['sections']['gedcom']['affected_records'])
            || !is_array($backup['sections']['gedcom']['affected_records'])
        ) {
            return;
        }
        foreach ($backup['sections']['gedcom']['affected_records'] as &$rec) {
            $xref = $rec['xref'] ?? null;
            if ($xref !== null && isset($afterMap[$xref])) {
                $rec['after_gedcom'] = $afterMap[$xref];
            }
        }
        unset($rec);
    }

    /**
     * @param array<string, mixed> $backup
     */
    private function writeBackup(array $backup): string
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0775, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException('Backup-Verzeichnis nicht anlegbar: ' . $this->backupDir);
        }

        $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $backup['src_value']);
        $fname = sprintf(
            '%s/%s_merge_%s.json',
            rtrim($this->backupDir, '/'),
            date('Y-m-d_His'),
            substr($safeName ?? 'x', 0, 40),
        );

        file_put_contents(
            $fname,
            json_encode($backup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
        return $fname;
    }

    /** @return array<string, mixed> */
    private function readBackup(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Backup-Datei nicht gefunden: ' . $path);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Backup-Datei nicht lesbar: ' . $path);
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function insertLogEntry(Tree $tree, string $op, int $srcId, int $dstId, string $backupPath): int
    {
        return (int) DB::table('ortsregister_merge_log')->insertGetId([
            'tree_id'      => $tree->id(),
            'operation'    => $op,
            'src_place_id' => $srcId,
            'dst_place_id' => $dstId,
            'user_id'      => Auth::id() ?: null,
            'backup_path'  => $backupPath,
            'status'       => 'completed',
        ]);
    }

    // ---------------------------------------------------------------
    // Sidecar-Vorbedingungen (Blattname, Mehrdeutigkeit, Koordinaten)
    // ---------------------------------------------------------------

    /**
     * Reverse-Lookup: voller Komma-Pfad → aktuelle place_id (oder null).
     * Disambiguiert mehrdeutige Blattnamen über den vollen Pfad. Für Undo
     * nötig, weil die place_id nach Reindex driftet (Härtungspunkt #7).
     */
    private function placeIdByFullName(Tree $tree, string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        $leaf = explode(', ', $value)[0];
        $rows = DB::table('places')
            ->where('p_file', '=', $tree->id())
            ->where('p_place', '=', $leaf)
            ->select(['p_id'])
            ->get();
        foreach ($rows as $row) {
            $id = (int) $row->p_id;
            if ($this->fullPlaceName($tree, $id) === $value) {
                return $id;
            }
        }
        return null;
    }

    /** Blatt-Name (p_place) eines Place-Records — Schlüssel für den Sidecar-Ordner. */
    private function leafName(Tree $tree, int $placeId): string
    {
        $row = DB::table('places')
            ->where('p_id', '=', $placeId)
            ->where('p_file', '=', $tree->id())
            ->select(['p_place'])
            ->first();
        return $row !== null ? (string) $row->p_place : '';
    }

    /**
     * Teilt mehr als ein Ort im Baum diesen Blattnamen? Dann ist die
     * Ordner-Zuordnung (am Blattnamen gekeyt) nicht eindeutig → Ordner-Merge
     * wird übersprungen (Interims-Wächter, siehe Konzept-Memo Entscheidung 1).
     */
    private function leafAmbiguous(Tree $tree, string $leaf): bool
    {
        if ($leaf === '') {
            return false;
        }
        return DB::table('places')
            ->where('p_file', '=', $tree->id())
            ->where('p_place', '=', $leaf)
            ->count() > 1;
    }

    /**
     * Sind zwei PLAC-Werte „fast identisch" — gleich nach Trimmen, Whitespace-
     * Normalisierung, Entfernen von Rand-Satzzeichen und Groß-/Kleinschreibung?
     * Erkennt Tippfehler-Paare wie „X" vs „X." rein mechanisch (keine Semantik).
     */
    private function nearIdentical(string $a, string $b): bool
    {
        $norm = static function (string $s): string {
            $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
            $s = trim($s, " \t.,;");
            return mb_strtolower($s);
        };
        return $a !== $b && $norm($a) === $norm($b);
    }

    /** Existiert (mindestens) ein Ort mit diesem Blattnamen im Baum? */
    private function leafExists(Tree $tree, string $leaf): bool
    {
        if ($leaf === '') {
            return false;
        }
        return DB::table('places')
            ->where('p_file', '=', $tree->id())
            ->where('p_place', '=', $leaf)
            ->exists();
    }

    /** Hat dieser Blattname Koordinaten in webtrees' place_location? */
    private function hasCoordinates(string $leaf): bool
    {
        if ($leaf === '') {
            return false;
        }
        return DB::table('place_location')
            ->where('place', '=', $leaf)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->exists();
    }
}
