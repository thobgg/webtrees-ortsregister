<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Fisharebest\Webtrees\Tree;

/**
 * Liefert Archion-Deep-URLs pro Ort. Zwei-Level-Lookup:
 *
 *   1. media/<root>/<ortsname>/_archion.json   (per-place, Vorrang)
 *      Format: {"url": "https://www.archion.de/de/alle-archive/..."}
 *
 *   2. media/<root>/_archion-urls.json          (Single-Map als Fallback)
 *      Format: {"Ortsname": "https://...", "Ortsname2": "https://..."}
 *
 * KEINE URL-Konstruktion, KEIN Netzwerkzugriff, KEIN Slug-Raten.
 * Der User pflegt die fertigen URLs (z.B. via KIES-DB-Export).
 *
 * Map wird pro Tree einmal pro Request gecached (Request-Scope), nicht über
 * ApcuCache — damit Edits sofort sichtbar werden.
 */
final class ArchionLinker
{
    public const PER_PLACE_FILE = '_archion.json';
    public const MAP_FILE       = '_archion-urls.json';

    /** @var array<int, array<string, string>> */
    private array $mapCache = [];

    public function __construct(
        private readonly PlaceFolderLocator $folderLocator = new PlaceFolderLocator(),
        private readonly ?ArchionParishLookup $parishLookup = null,
        private readonly float $autoMaxDistanceKm = 10.0,
    ) {}

    /**
     * Liefert die Archion-URL für einen Ort, oder null wenn keine bekannt.
     *
     * Lookup-Reihenfolge:
     *   1. Per-Place-File `_archion.json` (User-Override)
     *   2. Global-Map `_archion-urls.json` (User-managed)
     *   3. Auto-Lookup via archionkarte-20 (nearest Pfarrei in Koord-Radius)
     *   4. null (Caller fällt auf generische Such-URL zurück)
     *
     * @param float|null $lat  GOV-Koordinaten falls vorhanden — für Auto-Lookup nötig
     * @param float|null $lon
     */
    public function forPlace(Tree $tree, string $placeName, ?float $lat = null, ?float $lon = null): ?string
    {
        $placeName = trim($placeName);
        if (!$this->isValidPlaceName($placeName)) {
            return null;
        }
        // 1. Per-Place hat Vorrang
        $perPlace = $this->readPerPlace($tree, $placeName);
        if ($perPlace !== null) {
            return $perPlace;
        }
        // 2. Single-Map
        $map = $this->readMap($tree);
        if (isset($map[$placeName])) {
            return $map[$placeName];
        }
        // 3. Auto-Lookup via Koordinaten
        if ($this->parishLookup !== null && $lat !== null && $lon !== null) {
            $parish = $this->parishLookup->nearestWithin($lat, $lon, $this->autoMaxDistanceKm);
            if ($parish !== null) {
                return $parish->fullUrl();
            }
        }
        return null;
    }

    /**
     * Wie forPlace(), liefert aber auch Auskunft über die Lookup-Quelle.
     *
     * @return array{url: string, source: string}|null
     */
    public function forPlaceWithSource(Tree $tree, string $placeName, ?float $lat = null, ?float $lon = null): ?array
    {
        $placeName = trim($placeName);
        if (!$this->isValidPlaceName($placeName)) {
            return null;
        }
        $perPlace = $this->readPerPlace($tree, $placeName);
        if ($perPlace !== null) {
            return ['url' => $perPlace, 'source' => 'per-place'];
        }
        $map = $this->readMap($tree);
        if (isset($map[$placeName])) {
            return ['url' => $map[$placeName], 'source' => 'map'];
        }
        if ($this->parishLookup !== null && $lat !== null && $lon !== null) {
            $parish = $this->parishLookup->nearestWithin($lat, $lon, $this->autoMaxDistanceKm);
            if ($parish !== null) {
                return ['url' => $parish->fullUrl(), 'source' => 'auto:' . $parish->name];
            }
        }
        return null;
    }

    private function readPerPlace(Tree $tree, string $placeName): ?string
    {
        $folder = $this->folderLocator->folder($tree, $placeName);
        if ($folder === null) {
            return null;
        }
        $path = $folder . '/' . self::PER_PLACE_FILE;
        if (!is_file($path)) {
            return null;
        }
        $decoded = $this->loadJson($path);
        if (!is_array($decoded)) {
            return null;
        }
        $url = $decoded['url'] ?? null;
        return is_string($url) && $this->isHttpUrl($url) ? $url : null;
    }

    /**
     * @return array<string, string>
     */
    private function readMap(Tree $tree): array
    {
        $treeId = $tree->id();
        if (isset($this->mapCache[$treeId])) {
            return $this->mapCache[$treeId];
        }
        $path = $this->folderLocator->root($tree) . '/' . self::MAP_FILE;
        $map  = [];
        if (is_file($path)) {
            $decoded = $this->loadJson($path);
            if (is_array($decoded)) {
                foreach ($decoded as $name => $url) {
                    if (is_string($name) && is_string($url) && $this->isHttpUrl($url)) {
                        $map[trim($name)] = $url;
                    }
                }
            }
        }
        $this->mapCache[$treeId] = $map;
        return $map;
    }

    /**
     * @return mixed
     */
    private function loadJson(string $path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function isValidPlaceName(string $name): bool
    {
        return $name !== ''
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !str_contains($name, '..');
    }

    /**
     * Minimal-Validierung — nur http/https-Schemata akzeptieren.
     * Schützt davor, dass kaputter Input javascript:- oder data:-URLs durchwinkt.
     */
    private function isHttpUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return $scheme === 'http' || $scheme === 'https';
    }
}
