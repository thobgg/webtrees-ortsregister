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
    private const BACKUP_VERSION = 1;

    public function __construct(
        private readonly ApcuCacheService        $cache,
        private readonly GedcomPlaceManipulator  $manipulator,
        private readonly string                  $backupDir,
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
                . 'Mit dem Merge können _LOC-Records verwaisen — separate Aufräum-Operation in Phase 3+.';
        }

        return new MergeAnalysis(
            sourcePlaceId:    $srcId,
            targetPlaceId:    $dstId,
            sourceValue:      $srcValue,
            targetValue:      $dstValue,
            affectedCounts:   $counts,
            conflicts:        $conflicts,
            warnings:         $warnings,
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

        $result = DB::connection()->transaction(function () use (
            $tree, $srcId, $dstId, $srcValue, $dstValue,
            $affected, $targetSubtags, $resolutions
        ): MergeResult {
            // Backup einsammeln (BEFORE-Snapshot der GEDCOM-Strings)
            $backup = $this->buildBackup($tree, $srcId, $dstId, $srcValue, $dstValue, $affected);

            // Modifikationen anwenden
            $modified = 0;
            foreach ($affected as $entry) {
                $record = $this->loadRecord($tree, $entry['xref'], $entry['type']);
                if ($record === null) {
                    continue;
                }
                $oldGedcom = $record->gedcom();
                $newGedcom = $this->manipulator->replacePlacBlock(
                    $oldGedcom,
                    $srcValue,
                    $dstValue,
                    $targetSubtags,
                    $resolutions,
                );

                if ($newGedcom === $oldGedcom) {
                    continue;
                }

                // GEDCOM-persistent: webtrees-Core schreibt + re-indexed alles
                $record->updateRecord($newGedcom, false);
                $modified++;
            }

            // Hook für Phase 4: kuratorische Schicht mit-mergen.
            // Heute: Tabelle leer, 0-Rows-UPDATE.
            $this->mergePlaceMeta($tree, $srcId, $dstId);

            // Backup persistieren + Log
            $backupPath = $this->writeBackup($backup);
            $logId      = $this->insertLogEntry($tree, 'merge', $srcId, $dstId, $backupPath);

            return new MergeResult(
                sourcePlaceId:   $srcId,
                targetPlaceId:   $dstId,
                modifiedRecords: $modified,
                backupPath:      $backupPath,
                logId:           $logId,
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

        $backup = $this->readBackup($backupPath);
        if (($backup['version'] ?? 0) !== self::BACKUP_VERSION) {
            throw new RuntimeException('Unbekannte Backup-Version: ' . ($backup['version'] ?? '?'));
        }

        $gedcomSection = $backup['sections']['gedcom']['affected_records'] ?? [];
        $restored = 0;

        DB::connection()->transaction(function () use ($tree, $gedcomSection, &$restored): void {
            foreach ($gedcomSection as $entry) {
                $record = $this->loadRecord($tree, $entry['xref'], $entry['type']);
                if ($record === null) {
                    continue;
                }
                $record->updateRecord((string) $entry['before_gedcom'], false);
                $restored++;
            }
        });

        $this->cache->flush();
        return $restored;
    }

    // ---------------------------------------------------------------
    // Intern: Lade-Helfer
    // ---------------------------------------------------------------

    /** @return array{0: string, 1: string} [srcValue, dstValue] */
    private function loadPlaceValues(Tree $tree, int $srcId, int $dstId): array
    {
        $rows = DB::table('places')
            ->whereIn('p_id', [$srcId, $dstId])
            ->where('p_file', '=', $tree->id())
            ->pluck('p_place', 'p_id')
            ->all();

        if (!isset($rows[$srcId]) || !isset($rows[$dstId])) {
            throw new RuntimeException('Quell- oder Ziel-Place nicht gefunden.');
        }
        return [(string) $rows[$srcId], (string) $rows[$dstId]];
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
    // Hook für Phase 4: kuratorische Schicht
    // ---------------------------------------------------------------

    /**
     * Migriert place_meta-Einträge der Quelle ans Ziel.
     *
     * Phase 1: Tabelle leer, UPDATE betrifft 0 Zeilen.
     * Phase 4: hier wird Konflikt-Resolve-Logik für HTML/Foto/Galerie/Links
     * eingefügt (Vereinigung der akkumulierenden Felder, User-Entscheidung
     * für skalare Felder wie Hauptfoto).
     */
    private function mergePlaceMeta(Tree $tree, int $srcId, int $dstId): void
    {
        DB::table('ortsregister_place_meta')
            ->where('tree_id', '=', $tree->id())
            ->where('place_id', '=', $srcId)
            ->update(['place_id' => $dstId]);
    }
}
