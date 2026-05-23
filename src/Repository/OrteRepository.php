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

    public function __construct(
        private readonly ApcuCacheService $cache
    ) {}

    // ---------------------------------------------------------------
    // Öffentliche API
    // ---------------------------------------------------------------

    /**
     * Gibt alle Orte eines Baums zurück, optional gefiltert nach Namen.
     *
     * @return list<OrtDto>
     */
    public function alleOrte(Tree $tree, string $filter = ''): array
    {
        $cacheKey = sprintf('orte:%d:%s', $tree->id(), md5($filter));

        return $this->cache->remember($cacheKey, function () use ($tree, $filter) {
            return $this->queryAlleOrte($tree, $filter);
        }, self::CACHE_TTL);
    }

    /**
     * Gesamtzahl der Orte im Baum (für Paginierung).
     */
    public function anzahlOrte(Tree $tree, string $filter = ''): int
    {
        $cacheKey = sprintf('orte_count:%d:%s', $tree->id(), md5($filter));

        return $this->cache->remember($cacheKey, function () use ($tree, $filter) {
            return $this->queryAnzahlOrte($tree, $filter);
        }, self::CACHE_TTL);
    }

    /**
     * Gibt einen einzelnen Ort anhand seiner ID zurück.
     */
    public function findeOrtById(Tree $tree, int $id): ?OrtDto
    {
        $cacheKey = sprintf('ort:%d:%d', $tree->id(), $id);

        return $this->cache->remember($cacheKey, function () use ($tree, $id) {
            return $this->queryOrtById($tree, $id);
        }, self::CACHE_TTL);
    }

    /**
     * Löscht den Cache für alle Orte dieses Baums.
     */
    public function invalidateCache(Tree $tree): void
    {
        $this->cache->flush();
    }

    // ---------------------------------------------------------------
    // Interne Queries
    // ---------------------------------------------------------------

    /** @return list<OrtDto> */
    private function queryAlleOrte(Tree $tree, string $filter): array
    {
        $query = $this->baseQuery($tree);

        if ($filter !== '') {
            $like = '%' . addcslashes($filter, '%_') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('p.p_place', 'LIKE', $like)
                  ->orWhere('parent.p_place', 'LIKE', $like);
            });
        }

        $rows = $query
            ->orderBy('p.p_place')
            ->get();

        return $rows
            ->map(fn (object $row) => $this->rowToDto($row))
            ->values()
            ->all();
    }

    private function queryAnzahlOrte(Tree $tree, string $filter): int
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

        return (int) $query->count('p.p_id');
    }

    private function queryOrtById(Tree $tree, int $id): ?OrtDto
    {
        $row = $this->baseQuery($tree)
            ->where('p.p_id', '=', $id)
            ->first();

        return $row !== null ? $this->rowToDto($row) : null;
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
            ->leftJoin('place_location AS loc', 'loc.place', '=', 'p.p_place')
            ->where('p.p_file', '=', $tree->id())
            ->select([
                'p.p_id',
                'p.p_place',
                'parent.p_place AS parent_place',
            ])
            ->selectRaw("COUNT(DISTINCT {$prefix}pl.pl_gid)  AS anzahl_ereignisse")
            ->selectRaw("MAX({$prefix}loc.latitude)  AS breitengrad")
            ->selectRaw("MAX({$prefix}loc.longitude) AS laengengrad")
            ->groupBy(
                'p.p_id',
                'p.p_place',
                'parent.p_place',
            );
    }

    // ---------------------------------------------------------------
    // Mapping
    // ---------------------------------------------------------------

    private function rowToDto(object $row): OrtDto
    {
        $pfad = $row->p_place;
        if (isset($row->parent_place) && $row->parent_place !== null) {
            $pfad .= ', ' . $row->parent_place;
        }

        return new OrtDto(
            id:                 (int) $row->p_id,
            name:               $row->p_place,
            vollstaendigerPfad: $pfad,
            anzahlEreignisse:   (int) ($row->anzahl_ereignisse ?? 0),
            breitengrad:        isset($row->breitengrad)  ? (float) $row->breitengrad  : null,
            laengengrad:        isset($row->laengengrad) ? (float) $row->laengengrad : null,
        );
    }
}
