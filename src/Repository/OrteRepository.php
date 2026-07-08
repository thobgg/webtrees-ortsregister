<?php

declare(strict_types=1);

namespace Ortsregister\Repository;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Dto\OrtDto;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;

/**
 * Repository für geografische Orte.
 *
 * Liest aus den webtrees-Tabellen:
 *   - `places`         (p_id, p_file, p_place, p_parent_id)
 *   - `placelinks`     (pl_p_id, pl_gid, pl_file)
 *
 * Alle Abfragen werden baumspezifisch via p_file / pl_file gefiltert.
 */
class OrteRepository
{
    /** Cache-TTL für Ortslisten (20 Minuten) */
    private const CACHE_TTL = 1200;

    /** Filter-Modi für die Liste */
    public const MODE_ALL    = 'all';     // alle Hierarchie-Ebenen
    public const MODE_LEAVES = 'leaves';  // nur Blätter (keine Place-Kinder)

    public function __construct(
        private readonly ApcuCacheService $cache
    ) {}

    // ---------------------------------------------------------------
    // Öffentliche API
    // ---------------------------------------------------------------

    /**
     * Gibt alle Orte eines Baums zurück, optional gefiltert nach Namen.
     *
     * @param string $mode MODE_ALL oder MODE_LEAVES
     * @return list<OrtDto>
     */
    public function alleOrte(Tree $tree, string $filter = '', string $mode = self::MODE_ALL): array
    {
        $mode     = $this->normalizeMode($mode);
        $cacheKey = sprintf('orte2:%d:%s:%s', $tree->id(), $mode, md5($filter));

        return $this->cache->remember($cacheKey, function () use ($tree, $filter, $mode) {
            return $this->queryAlleOrte($tree, $filter, $mode);
        }, self::CACHE_TTL);
    }

    /**
     * Gesamtzahl der Orte im Baum (für Paginierung).
     */
    public function anzahlOrte(Tree $tree, string $filter = '', string $mode = self::MODE_ALL): int
    {
        $mode     = $this->normalizeMode($mode);
        $cacheKey = sprintf('orte_count:%d:%s:%s', $tree->id(), $mode, md5($filter));

        return $this->cache->remember($cacheKey, function () use ($tree, $filter, $mode) {
            return $this->queryAnzahlOrte($tree, $filter, $mode);
        }, self::CACHE_TTL);
    }

    /** Schützt vor ungültigen Mode-Werten aus URL-/Pref-Input. */
    private function normalizeMode(string $mode): string
    {
        return $mode === self::MODE_LEAVES ? self::MODE_LEAVES : self::MODE_ALL;
    }

    /**
     * Gibt einen einzelnen Ort anhand seiner ID zurück.
     */
    public function findeOrtById(Tree $tree, int $id): ?OrtDto
    {
        $cacheKey = sprintf('ort2:%d:%d', $tree->id(), $id);

        return $this->cache->remember($cacheKey, function () use ($tree, $id) {
            return $this->queryOrtById($tree, $id);
        }, self::CACHE_TTL);
    }

    /**
     * Andere Orte im Baum, die laut GOV DERSELBE reale Ort sind — verknüpft auf
     * dieselbe GOV-Kennung (`ortsregister_place_meta.gov_id`) wie $placeId.
     *
     * Das sind die Zeit-/Gebietsreform-Varianten desselben Orts (Achse C): gleicher
     * realer Ort, verschiedene PLAC-Schreibweisen über die Zeit. Rein lesend, nutzt
     * nur vorhandene GOV-Verknüpfungen — schreibt nichts, ändert keine PLAC.
     *
     * @return list<array{id:int, pfad:string}>
     */
    public function govGeschwister(Tree $tree, int $placeId): array
    {
        $govId = DB::table('ortsregister_place_meta')
            ->where('tree_id',  '=', $tree->id())
            ->where('place_id', '=', $placeId)
            ->value('gov_id');
        if ($govId === null || (string) $govId === '') {
            return [];
        }

        $siblingIds = DB::table('ortsregister_place_meta')
            ->where('tree_id',  '=', $tree->id())
            ->where('gov_id',   '=', $govId)
            ->where('place_id', '!=', $placeId)
            ->pluck('place_id');
        if ($siblingIds->isEmpty()) {
            return [];
        }

        // Nur Orte mit vollem Pfad, die es im Baum wirklich (noch) gibt — filtert
        // verwaiste place_meta-Zeilen (Ort gelöscht, Meta blieb) automatisch aus.
        $pathMap = $this->buildPathMap($tree);
        $out = [];
        foreach ($siblingIds as $sid) {
            $sid = (int) $sid;
            if (isset($pathMap[$sid])) {
                $out[] = ['id' => $sid, 'pfad' => $pathMap[$sid]];
            }
        }
        usort($out, static fn(array $a, array $b): int => strnatcasecmp($a['pfad'], $b['pfad']));
        return $out;
    }

    /**
     * Löscht den Cache für alle Orte dieses Baums.
     */
    public function invalidateCache(Tree $tree): void
    {
        $this->cache->flush();
    }

    /**
     * Letzte Merge-/Rename-Operationen des Baums, neueste zuerst — für die
     * Verlaufs-/Undo-Liste auf der Ortsseite. Lesbare Ortsnamen kommen aus der
     * Backup-JSON (die Quell-place_id existiert nach dem Merge nicht mehr).
     * Nicht gecacht: die Liste soll nach jedem Merge/Undo sofort aktuell sein.
     *
     * @return list<array{id: int, operation: string, status: string, created_at: string, src: string, dst: string}>
     */
    public function letzteOperationen(Tree $tree, int $limit = 10): array
    {
        $rows = DB::table('ortsregister_merge_log')
            ->where('tree_id', '=', $tree->id())
            // Nur Operationen, die der globale Merge/Rename-Undo-Handler zurückspielen
            // kann. `loc_write` teilt dieselbe Log-Tabelle, hat aber ein eigenes
            // Backup-Format + eigenen Undo-Endpoint → hier NICHT auflisten.
            ->whereIn('operation', ['merge', 'rename'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $ops = [];
        foreach ($rows as $row) {
            [$src, $dst] = $this->labelForOperation($row);
            $ops[] = [
                'id'         => (int) $row->id,
                'operation'  => (string) $row->operation,
                'status'     => (string) $row->status,
                'created_at' => (string) $row->created_at,
                'src'        => $src,
                'dst'        => $dst,
            ];
        }

        return $ops;
    }

    /**
     * Lesbare Quell-/Ziel-Namen aus der Backup-JSON; Fallback auf die place_ids,
     * falls die Datei fehlt (z. B. manuell aufgeräumt).
     *
     * @return array{0: string, 1: string} [src, dst]
     */
    private function labelForOperation(object $row): array
    {
        $path = (string) ($row->backup_path ?? '');
        if ($path !== '' && is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $src = (string) ($data['src_value'] ?? '');
                    $dst = (string) ($data['dst_value'] ?? '');
                    if ($src !== '' || $dst !== '') {
                        return [$src, $dst];
                    }
                }
            }
        }

        return ['#' . ($row->src_place_id ?? '?'), '#' . ($row->dst_place_id ?? '?')];
    }

    // ---------------------------------------------------------------
    // Interne Queries
    // ---------------------------------------------------------------

    /** @return list<OrtDto> */
    private function queryAlleOrte(Tree $tree, string $filter, string $mode): array
    {
        $query = $this->baseQuery($tree);

        if ($filter !== '') {
            $like = '%' . addcslashes($filter, '%_') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('p.p_place', 'LIKE', $like)
                  ->orWhere('parent.p_place', 'LIKE', $like);
            });
        }

        $this->applyModeFilter($query, $mode);

        $rows = $query
            ->orderBy('p.p_place')
            ->get();

        // Voller Komma-Pfad je Ort — damit zwei Orte mit gleichem Blatt+Elternteil
        // (z.B. "Brisbane, Queensland, Australia" vs "…Australia.") in der Liste
        // unterscheidbar sind. Einmal pro (gecachtem) Listenaufbau berechnet.
        $pathMap  = $this->buildPathMap($tree);
        $coordMap = $this->buildLocationCoordMap();

        return $rows
            ->map(function (object $row) use ($pathMap, $coordMap) {
                $fullPath    = $pathMap[(int) $row->p_id] ?? null;
                [$lat, $lon] = $fullPath !== null ? ($coordMap[$fullPath] ?? [null, null]) : [null, null];
                return $this->rowToDto($row, $fullPath, $lat, $lon);
            })
            ->values()
            ->all();
    }

    /**
     * Baut p_id → vollen Komma-Pfad für alle Orte des Baums (in-memory-Walk,
     * eine Query). Zyklus-geschützt.
     *
     * @return array<int, string>
     */
    private function buildPathMap(Tree $tree): array
    {
        $rows = DB::table('places')
            ->where('p_file', '=', $tree->id())
            ->select(['p_id', 'p_place', 'p_parent_id'])
            ->get();

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r->p_id] = [
                'place'  => (string) $r->p_place,
                'parent' => (int) $r->p_parent_id,
            ];
        }

        $paths = [];
        foreach ($byId as $id => $_unused) {
            $parts = [];
            $cur   = $id;
            $seen  = [];
            while ($cur > 0 && isset($byId[$cur]) && !isset($seen[$cur])) {
                $seen[$cur] = true;
                $parts[]    = $byId[$cur]['place'];
                $cur        = $byId[$cur]['parent'];
            }
            $paths[$id] = implode(', ', $parts);
        }
        return $paths;
    }

    /**
     * Baut vollen Komma-Pfad (Blatt-first, ", ") → [lat, lon] aus `place_location`.
     * Hierarchie-genau: unterscheidet gleichnamige Orte über den GANZEN Pfad statt nur
     * den Blattnamen (behebt die Leaf-Ambiguität des früheren `MAX`-Joins). Pfad-Bau
     * identisch zu buildPathMap(), damit die Schlüssel byte-genau matchen.
     * `place_location` ist global (nicht baum-scoped).
     *
     * @return array<string, array{0: float|null, 1: float|null}>
     */
    private function buildLocationCoordMap(): array
    {
        $rows = DB::table('place_location')
            ->select(['id', 'parent_id', 'place', 'latitude', 'longitude'])
            ->get();

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r->id] = [
                'place'  => (string) $r->place,
                'parent' => $r->parent_id === null ? 0 : (int) $r->parent_id,
                'lat'    => $r->latitude  === null ? null : (float) $r->latitude,
                'lon'    => $r->longitude === null ? null : (float) $r->longitude,
            ];
        }

        $map = [];
        foreach ($byId as $id => $info) {
            $parts = [];
            $cur   = $id;
            $seen  = [];
            while ($cur > 0 && isset($byId[$cur]) && !isset($seen[$cur])) {
                $seen[$cur] = true;
                $parts[]    = $byId[$cur]['place'];
                $cur        = $byId[$cur]['parent'];
            }
            // Nur Knoten mit Koordinaten sind interessant; kollidierende Pfade (selten,
            // via DB-Collation) — erster mit Koordinaten gewinnt.
            $key = implode(', ', $parts);
            if (!isset($map[$key]) || ($info['lat'] !== null && $info['lon'] !== null)) {
                $map[$key] = [$info['lat'], $info['lon']];
            }
        }
        return $map;
    }

    private function queryAnzahlOrte(Tree $tree, string $filter, string $mode): int
    {
        $query = DB::table('places AS p')
            ->where('p.p_file', '=', $tree->id());

        if ($filter !== '') {
            $like = '%' . addcslashes($filter, '%_') . '%';
            $query->leftJoin('places AS parent', 'parent.p_id', '=', 'p.p_parent_id')
                  ->where(function ($q) use ($like) {
                      $q->where('p.p_place', 'LIKE', $like)
                        ->orWhere('parent.p_place', 'LIKE', $like);
                  });
        }

        $this->applyModeFilter($query, $mode);

        return (int) $query->count('p.p_id');
    }

    /**
     * Wendet den Hierarchie-Filter an: MODE_LEAVES zeigt nur Orte ohne
     * Place-Kinder (Blätter der webtrees-Hierarchie). Verwaltungs-Ebenen
     * wie "Amt Kirchheim", "Hzm. Württemberg" werden ausgeblendet.
     */
    private function applyModeFilter(\Illuminate\Database\Query\Builder $query, string $mode): void
    {
        if ($mode !== self::MODE_LEAVES) {
            return;
        }
        $query->whereNotExists(function ($q): void {
            $q->select(DB::raw('1'))
              ->from('places AS child')
              ->whereColumn('child.p_parent_id', 'p.p_id')
              ->whereColumn('child.p_file',     'p.p_file');
        });
    }

    private function queryOrtById(Tree $tree, int $id): ?OrtDto
    {
        $row = $this->baseQuery($tree)
            ->where('p.p_id', '=', $id)
            ->first();

        if ($row === null) {
            return null;
        }

        $fullPath    = $this->buildPathMap($tree)[$id] ?? null;
        [$lat, $lon] = $fullPath !== null ? ($this->buildLocationCoordMap()[$fullPath] ?? [null, null]) : [null, null];

        return $this->rowToDto($row, $fullPath, $lat, $lon);
    }

    // ---------------------------------------------------------------
    // Query-Builder-Grundgerüst
    // ---------------------------------------------------------------

    private function baseQuery(Tree $tree): \Illuminate\Database\Query\Builder
    {
        // Laravel-Illuminate hängt den Tabellen-Prefix (z. B. "wt_") auch an
        // Alias-Namen in FROM/JOIN an, NICHT aber an Bezeichner innerhalb von
        // selectRaw(). Wir müssen den Prefix dort manuell ergänzen.
        $prefix = DB::connection()->getTablePrefix();

        return DB::table('places AS p')
            ->leftJoin('places AS parent', 'parent.p_id', '=', 'p.p_parent_id')
            ->leftJoin('placelinks AS pl', function ($join) use ($tree) {
                $join->on('pl.pl_p_id', '=', 'p.p_id')
                     ->where('pl.pl_file', '=', $tree->id());
            })
            ->leftJoin('ortsregister_place_meta AS meta', function ($join) use ($tree) {
                $join->on('meta.place_id', '=', 'p.p_id')
                     ->where('meta.tree_id', '=', $tree->id());
            })
            ->where('p.p_file', '=', $tree->id())
            ->select([
                'p.p_id',
                'p.p_place',
                'parent.p_place AS parent_place',
                'meta.gov_id',
            ])
            // Koordinaten NICHT mehr hier per Blattnamen-Join (das mischte gleichnamige
            // Orte). Sie kommen vollpfad-genau aus buildLocationCoordMap(), gemappt in
            // queryAlleOrte()/queryOrtById().
            ->selectRaw("COUNT(DISTINCT {$prefix}pl.pl_gid)  AS anzahl_ereignisse")
            ->groupBy(
                'p.p_id',
                'p.p_place',
                'parent.p_place',
                'meta.gov_id',
            );
    }

    // ---------------------------------------------------------------
    // Mapping
    // ---------------------------------------------------------------

    private function rowToDto(object $row, ?string $fullPath = null, ?float $lat = null, ?float $lon = null): OrtDto
    {
        if ($fullPath !== null && $fullPath !== '') {
            $pfad = $fullPath;
        } else {
            // Fallback: Blatt + direktes Elternteil (z.B. Einzel-Lookup).
            $pfad = $row->p_place;
            if (isset($row->parent_place) && $row->parent_place !== null) {
                $pfad .= ', ' . $row->parent_place;
            }
        }

        return new OrtDto(
            id:                 (int) $row->p_id,
            name:               $row->p_place,
            vollstaendigerPfad: $pfad,
            anzahlEreignisse:   (int) ($row->anzahl_ereignisse ?? 0),
            breitengrad:        $lat,
            laengengrad:        $lon,
            govId:              isset($row->gov_id) && $row->gov_id !== null ? (string) $row->gov_id : null,
        );
    }
}
