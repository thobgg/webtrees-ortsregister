<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\ArchionParish;

/**
 * Geo-Lookup gegen die archionkarte-20-Stammdaten aller deutschen ev. Pfarreien.
 *
 * Quelle: github.com/brger93/archionkarte-20 (MIT, brger93)
 * Daten: resources/data/archion-parishes.json (indexierte Form: archives[], districts[], parishes[{n,ai,di,p,g}])
 *
 * Lookup: Haversine-Distanz, lazy file-load, in-memory-Cache pro Request.
 */
class ArchionParishLookup
{
    /** @var list<ArchionParish>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $dataFile,
    ) {}

    /**
     * Findet die nächstgelegene Pfarrei im angegebenen Radius.
     *
     * @return ArchionParish|null  null wenn keine im Radius
     */
    public function nearestWithin(float $lat, float $lon, float $maxDistanceKm = 10.0): ?ArchionParish
    {
        $closest = null;
        $best    = $maxDistanceKm;
        foreach ($this->all() as $p) {
            if ($p->latitude === null || $p->longitude === null) continue;
            $d = $this->distanceKm($lat, $lon, $p->latitude, $p->longitude);
            if ($d < $best) {
                $best    = $d;
                $closest = $p;
            }
        }
        return $closest;
    }

    /**
     * Alle Pfarreien innerhalb des Radius, sortiert nach Distanz aufsteigend.
     *
     * @return list<array{parish: ArchionParish, distance_km: float}>
     */
    public function allWithin(float $lat, float $lon, float $maxDistanceKm = 10.0, int $limit = 10): array
    {
        $hits = [];
        foreach ($this->all() as $p) {
            if ($p->latitude === null || $p->longitude === null) continue;
            $d = $this->distanceKm($lat, $lon, $p->latitude, $p->longitude);
            if ($d <= $maxDistanceKm) {
                $hits[] = ['parish' => $p, 'distance_km' => $d];
            }
        }
        usort($hits, static fn(array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);
        return array_slice($hits, 0, $limit);
    }

    /**
     * @return list<ArchionParish>
     */
    private function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if (!is_file($this->dataFile)) {
            $this->cache = [];
            return $this->cache;
        }
        $raw = @file_get_contents($this->dataFile);
        if ($raw === false || $raw === '') {
            $this->cache = [];
            return $this->cache;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->cache = [];
            return $this->cache;
        }
        $archives  = is_array($decoded['archives'])  ? $decoded['archives']  : [];
        $districts = is_array($decoded['districts']) ? $decoded['districts'] : [];
        $parishes  = is_array($decoded['parishes'])  ? $decoded['parishes']  : [];

        $out = [];
        foreach ($parishes as $row) {
            if (!is_array($row)) continue;
            $name = (string) ($row['n'] ?? '');
            if ($name === '') continue;
            $ai = (int) ($row['ai'] ?? -1);
            $di = (int) ($row['di'] ?? -1);
            $coords = $row['g'] ?? [null, null];
            $lon = is_array($coords) && isset($coords[0]) && is_numeric($coords[0]) ? (float) $coords[0] : null;
            $lat = is_array($coords) && isset($coords[1]) && is_numeric($coords[1]) ? (float) $coords[1] : null;
            $out[] = new ArchionParish(
                name:      $name,
                archive:   $archives[$ai]  ?? '',
                district:  $districts[$di] ?? '',
                path:      (string) ($row['p'] ?? ''),
                latitude:  $lat,
                longitude: $lon,
            );
        }
        $this->cache = $out;
        return $out;
    }

    /** Haversine-Distanz in km. */
    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
