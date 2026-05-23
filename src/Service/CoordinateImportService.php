<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Cache\ApcuCacheService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\PlaceLocation;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use RuntimeException;

/**
 * Importiert PLAC-Subtag-Koordinaten (MAP/LATI/LONG) aus dem GEDCOM
 * in die webtrees-Standardtabelle `place_location`.
 *
 * Hintergrund: Ahnenblatt / Gramps / FTM / MyHeritage exportieren
 * Koordinaten als PLAC-Subtags. webtrees ignoriert diese bei der
 * Anzeige und nutzt nur `place_location`. Diese Operation überträgt
 * die Daten einmalig.
 *
 * Idempotent: kann mehrfach laufen. Existierende Locations werden
 * nicht überschrieben außer ihre Koordinaten sind NULL.
 *
 * Backup als JSON vor Schreiben — selbstständig wiederherstellbar.
 */
class CoordinateImportService
{
    private const BACKUP_VERSION = 1;

    public function __construct(
        private readonly ApcuCacheService           $cache,
        private readonly GedcomCoordinateExtractor  $extractor,
        private readonly string                     $backupDir,
    ) {}

    /**
     * Pre-Flight: was würde der Import bewirken? Read-only.
     *
     * @return array{
     *     unique_placs_with_coords: int,
     *     already_in_place_location: int,
     *     would_insert: int,
     *     would_update: int,
     * }
     */
    public function analyzeImport(Tree $tree): array
    {
        $coords = $this->collectCoordinatesFromGedcom($tree);

        $alreadyComplete = 0;
        $wouldUpdate     = 0;
        $wouldInsert     = 0;

        foreach ($coords as $placValue => [$lat, $lng]) {
            $existing = $this->findLocationByName($placValue);
            if ($existing === null) {
                $wouldInsert++;
            } elseif ($existing->latitude === null || $existing->longitude === null) {
                $wouldUpdate++;
            } else {
                $alreadyComplete++;
            }
        }

        return [
            'unique_placs_with_coords'  => count($coords),
            'already_in_place_location' => $alreadyComplete,
            'would_insert'              => $wouldInsert,
            'would_update'              => $wouldUpdate,
        ];
    }

    /**
     * Führt den Import durch.
     *
     * @return array{written: int, skipped: int, backup: string}
     */
    public function executeImport(Tree $tree): array
    {
        $this->assertAutoAcceptEdits();

        $coords = $this->collectCoordinatesFromGedcom($tree);
        if ($coords === []) {
            throw new RuntimeException('Keine Koordinaten im GEDCOM gefunden.');
        }

        // Backup
        $backup = $this->buildBackup($tree, $coords);
        $backupPath = $this->writeBackup($backup);

        $written = 0;
        $skipped = 0;

        DB::connection()->transaction(function () use ($coords, &$written, &$skipped): void {
            foreach ($coords as $placValue => [$lat, $lng]) {
                // PlaceLocation legt fehlende Hierarchie-Einträge automatisch an.
                // (new PlaceLocation($name))->id() ist self-bootstrapping.
                $location = new PlaceLocation($placValue);
                $id       = $location->id();

                // Existierende Koordinaten NICHT überschreiben (idempotent)
                $existing = DB::table('place_location')
                    ->where('id', '=', $id)
                    ->first(['latitude', 'longitude']);

                if ($existing !== null
                    && $existing->latitude !== null
                    && $existing->longitude !== null) {
                    $skipped++;
                    continue;
                }

                DB::table('place_location')
                    ->where('id', '=', $id)
                    ->update(['latitude' => $lat, 'longitude' => $lng]);
                $written++;
            }
        });

        $this->cache->flush();

        return [
            'written' => $written,
            'skipped' => $skipped,
            'backup'  => $backupPath,
        ];
    }

    // ---------------------------------------------------------------
    // Intern: GEDCOM-Iteration
    // ---------------------------------------------------------------

    /**
     * Sammelt alle PLAC-Koordinaten aus allen Records eines Trees.
     * Bei mehrfachen Vorkommen desselben PLAC-Werts gewinnt das erste.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private function collectCoordinatesFromGedcom(Tree $tree): array
    {
        $coords = [];
        $tables = [
            ['individuals', 'i_gedcom', 'i_file'],
            ['families',    'f_gedcom', 'f_file'],
            ['sources',     's_gedcom', 's_file'],
            ['media',       'm_gedcom', 'm_file'],
        ];
        foreach ($tables as [$table, $col, $fileCol]) {
            DB::table($table)
                ->where($fileCol, '=', $tree->id())
                ->select([$col])
                ->orderBy($col === 'i_gedcom' ? 'i_id' : ($col === 'f_gedcom' ? 'f_id' : ($col === 's_gedcom' ? 's_id' : 'm_id')))
                ->chunk(500, function ($rows) use ($col, &$coords): void {
                    foreach ($rows as $row) {
                        $gedcom = (string) $row->{$col};
                        if ($gedcom === '' || !str_contains($gedcom, 'PLAC')) {
                            continue;
                        }
                        foreach ($this->extractor->extract($gedcom) as [$placValue, $lat, $lng]) {
                            if (!isset($coords[$placValue])) {
                                $coords[$placValue] = [$lat, $lng];
                            }
                        }
                    }
                });
        }
        return $coords;
    }

    private function findLocationByName(string $placValue): ?object
    {
        // PlaceLocation-Lookup über Komma-Pfad ist intern teuer — wir prüfen
        // hier nur den OBERSTEN Place-Namen für eine Quick-Statistik, NICHT
        // für die echte Schreiboperation. Das ist nur fürs analyze().
        $parts = array_reverse(array_map('trim', explode(',', $placValue)));
        $parentId = null;
        $row = null;
        foreach ($parts as $part) {
            $q = DB::table('place_location')
                ->where('place', '=', $part);
            if ($parentId === null) {
                $q->whereNull('parent_id');
            } else {
                $q->where('parent_id', '=', $parentId);
            }
            $row = $q->select(['id', 'latitude', 'longitude'])->first();
            if ($row === null) {
                return null;
            }
            $parentId = (int) $row->id;
        }
        return $row;
    }

    // ---------------------------------------------------------------
    // Backup
    // ---------------------------------------------------------------

    /**
     * @param array<string, array{0: float, 1: float}> $coords
     * @return array<string, mixed>
     */
    private function buildBackup(Tree $tree, array $coords): array
    {
        // Snapshot aller existierenden Koordinaten BEVOR wir schreiben
        $existing = DB::table('place_location')
            ->whereNotNull('latitude')
            ->orWhereNotNull('longitude')
            ->select(['id', 'place', 'parent_id', 'latitude', 'longitude'])
            ->get()
            ->toArray();

        return [
            'version'   => self::BACKUP_VERSION,
            'operation' => 'coordinate_import',
            'timestamp' => date('c'),
            'tree_id'   => $tree->id(),
            'user_id'   => Auth::id(),
            'will_apply_count' => count($coords),
            'sections'  => [
                'place_location_before' => $existing,
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
        $fname = sprintf('%s/%s_coord_import.json', rtrim($this->backupDir, '/'), date('Y-m-d_His'));
        file_put_contents(
            $fname,
            json_encode($backup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
        return $fname;
    }

    private function assertAutoAcceptEdits(): void
    {
        if (Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) !== '1') {
            throw new RuntimeException(
                'Operation erfordert aktivierte User-Einstellung „Änderungen automatisch übernehmen".',
            );
        }
    }
}
