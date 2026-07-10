<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Dto\WikiImage;
use Ortsregister\Dto\WikimediaPlaceData;

/**
 * Holt zu einem Ortsnamen passende Wikimedia-Daten:
 *   1. Wikidata-Search (de) → bis zu 5 Kandidaten
 *   2. Geo-Validation gegen GOV-Koordinaten (max. 30 km Abstand) →  QID
 *   3. P18 (Hauptbild) aus dem QID → Commons-FileInfo
 *   4. Commons-Search („File:<ortsname>") → bis zu 8 Galerie-Bilder
 *
 * Strategie nach KIES-Vorbild. Alle Calls sind separate APIs, jeder kann
 * fehlschlagen — bei jedem Fehler liefert die Methode bisher gesammelte
 * Daten oder einen leeren DTO. Die Detailseite darf nicht abstürzen.
 *
 * Cache 7 Tage (Wikidata-Daten ändern sich selten).
 */
class WikimediaPlaceClient
{
    private const TIMEOUT    = 5;
    private const USER_AGENT = 'webtrees-ortsregister/0.1 (+https://github.com/thobgg/webtrees-ortsregister)';

    public function __construct(
        private readonly ApcuCacheService $cache,
        private readonly int              $maxDistanceKm = 30,
        private readonly int              $cacheTtl      = 604800,
        private readonly bool             $enabled       = true,
    ) {}

    /**
     * Liefert Wikimedia-Daten für den Ort. Liefert immer einen DTO —
     * leer wenn nichts gefunden oder Lookup per Setting abgeschaltet.
     */
    public function lookup(string $placeName, ?float $govLat, ?float $govLon): WikimediaPlaceData
    {
        if (!$this->enabled) {
            return WikimediaPlaceData::empty();
        }
        $placeName = trim($placeName);
        if ($placeName === '') {
            return WikimediaPlaceData::empty();
        }

        // Doktrin „nie raten" (Hermanns #9, Klosterstraße 3): ohne Orts-Koordinaten
        // lässt sich ein Wikidata-Namenstreffer nicht geo-validieren — mehrdeutige
        // Namen (Hausadressen, Friedhöfe) griffen dann ins Falsche, und Bilder UND
        // Wikipedia-Link wären falsch. Lieber nichts zeigen als das falsche Objekt;
        // Wikipedia fällt in der View auf den Such-Link zurück.
        if ($govLat === null || $govLon === null) {
            return WikimediaPlaceData::empty();
        }

        // Key-Version 2: seit dem Sitelinks-Feld im DTO — alte gecachte Objekte
        // (ohne das Feld) dürfen nicht mehr ausgeliefert werden.
        $cacheKey = sprintf('wmp2:%s:%s:%s',
            md5($placeName),
            $govLat !== null ? round($govLat, 3) : '_',
            $govLon !== null ? round($govLon, 3) : '_',
        );

        return $this->cache->remember($cacheKey, function () use ($placeName, $govLat, $govLon): WikimediaPlaceData {
            return $this->fetchAndBuild($placeName, $govLat, $govLon);
        }, $this->cacheTtl);
    }

    private function fetchAndBuild(string $placeName, ?float $govLat, ?float $govLon): WikimediaPlaceData
    {
        $qid = $this->findQid($placeName, $govLat, $govLon);
        if ($qid === null) {
            return WikimediaPlaceData::empty();
        }

        // Entity EINMAL holen → Hauptbild (P18) + Wikipedia-Sitelinks daraus.
        $entity    = $this->httpGetJson("https://www.wikidata.org/wiki/Special:EntityData/$qid.json");
        $hauptbild = $entity !== null ? $this->imageFromEntity($entity, $qid) : null;
        $sitelinks = $entity !== null ? $this->extractSitelinks($entity, $qid) : [];

        $galerie = $this->fetchGalerie($placeName);

        // Wenn das Hauptbild auch in der Galerie auftaucht → aus Galerie entfernen
        if ($hauptbild !== null) {
            $galerie = array_values(array_filter(
                $galerie,
                static fn(WikiImage $g) => $g->thumbUrl !== $hauptbild->thumbUrl,
            ));
        }
        // Kappung auf max. 6 Galerie-Bilder
        $galerie = array_slice($galerie, 0, 6);

        return new WikimediaPlaceData($qid, $hauptbild, $galerie, $sitelinks);
    }

    /**
     * Wikidata-Search → max. 5 Kandidaten → P625 geo-validieren → erstes geeignetes QID.
     */
    private function findQid(string $placeName, ?float $govLat, ?float $govLon): ?string
    {
        $url = 'https://www.wikidata.org/w/api.php?action=wbsearchentities&format=json'
            . '&type=item&language=de&limit=5&search=' . rawurlencode($placeName);
        $json = $this->httpGetJson($url);
        if ($json === null) {
            return null;
        }
        $candidates = $json['search'] ?? [];
        if (!is_array($candidates)) {
            return null;
        }

        foreach ($candidates as $cand) {
            $kid = $cand['id'] ?? null;
            if (!is_string($kid) || $kid === '') {
                continue;
            }
            // EntityData laden, P625 prüfen
            $entityJson = $this->httpGetJson("https://www.wikidata.org/wiki/Special:EntityData/$kid.json");
            if ($entityJson === null) {
                continue;
            }
            $claims = $entityJson['entities'][$kid]['claims'] ?? [];
            $p625   = $claims['P625'] ?? [];

            if ($p625 !== [] && is_array($p625)) {
                $value = $p625[0]['mainsnak']['datavalue']['value'] ?? null;
                $klat  = is_array($value) && isset($value['latitude'])  ? (float) $value['latitude']  : null;
                $klon  = is_array($value) && isset($value['longitude']) ? (float) $value['longitude'] : null;

                if ($govLat !== null && $govLon !== null && $klat !== null && $klon !== null) {
                    if ($this->distanceKm($govLat, $govLon, $klat, $klon) > $this->maxDistanceKm) {
                        continue;
                    }
                }
                return $kid;
            }
            // Kein P625: nur akzeptieren wenn wir auch keine GOV-Koord haben (sonst unsicher)
            if ($govLat === null || $govLon === null) {
                return $kid;
            }
        }
        return null;
    }

    /**
     * P18-Property auflösen → Commons-FileInfo holen → WikiImage.
     */
    /** P18-Hauptbild aus schon geholter Entity-JSON. */
    private function imageFromEntity(array $entityJson, string $qid): ?WikiImage
    {
        $p18 = $entityJson['entities'][$qid]['claims']['P18'] ?? [];
        if (!is_array($p18) || $p18 === []) {
            return null;
        }
        $filename = $p18[0]['mainsnak']['datavalue']['value'] ?? null;
        if (!is_string($filename) || $filename === '') {
            return null;
        }
        return $this->commonsFileInfo('File:' . str_replace(' ', '_', $filename), 800);
    }

    /**
     * Wikipedia-Sitelinks aus der Entity-JSON: Sprach-Code → Artikel-URL. REIN, testbar.
     * Nimmt nur echte Wikipedias (`<lang>wiki`), keine Schwester-Projekte (commons/meta/…).
     *
     * @param array<string,mixed> $entityJson
     * @return array<string,string>
     */
    public function extractSitelinks(array $entityJson, string $qid): array
    {
        $sitelinks = $entityJson['entities'][$qid]['sitelinks'] ?? null;
        if (!is_array($sitelinks)) {
            return [];
        }
        static $notLanguage = ['commons', 'meta', 'species', 'wikidata', 'mediawiki', 'sources', 'incubator', 'wikimania', 'foundation', 'outreach'];
        $out = [];
        foreach ($sitelinks as $dbname => $sl) {
            if (!is_string($dbname) || !str_ends_with($dbname, 'wiki')) {
                continue;
            }
            $lang = substr($dbname, 0, -4);
            if ($lang === '' || in_array($lang, $notLanguage, true)) {
                continue;
            }
            $url = is_array($sl) ? ($sl['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                $out[str_replace('_', '-', $lang)] = $url;
            }
        }
        return $out;
    }

    /**
     * Commons-Search nach Dateien mit dem Ortsnamen im Titel (NS=6) → bis zu 8 Bilder.
     *
     * @return list<WikiImage>
     */
    private function fetchGalerie(string $placeName): array
    {
        $searchUrl = 'https://commons.wikimedia.org/w/api.php?action=query&format=json'
            . '&list=search&srnamespace=6&srlimit=14&srsearch=' . rawurlencode($placeName);
        $searchJson = $this->httpGetJson($searchUrl);
        if ($searchJson === null) {
            return [];
        }

        $titles = [];
        foreach (($searchJson['query']['search'] ?? []) as $hit) {
            $t = $hit['title'] ?? null;
            if (!is_string($t)) continue;
            $lower = strtolower($t);
            if (str_ends_with($lower, '.svg') || str_ends_with($lower, '.png')) {
                continue;
            }
            $titles[] = $t;
            if (count($titles) >= 8) {
                break;
            }
        }
        if ($titles === []) {
            return [];
        }

        $tEnc = implode('|', array_map('rawurlencode', $titles));
        $infoUrl = 'https://commons.wikimedia.org/w/api.php?action=query&format=json'
            . '&prop=imageinfo&iiprop=url|extmetadata&iiurlwidth=300&titles=' . $tEnc;
        $infoJson = $this->httpGetJson($infoUrl);
        if ($infoJson === null) {
            return [];
        }

        $out = [];
        foreach (($infoJson['query']['pages'] ?? []) as $page) {
            $img = $this->parseImageInfo($page);
            if ($img !== null) {
                $out[] = $img;
            }
        }
        return $out;
    }

    private function commonsFileInfo(string $fileTitle, int $width): ?WikiImage
    {
        $url = 'https://commons.wikimedia.org/w/api.php?action=query&format=json'
            . '&prop=imageinfo&iiprop=url|extmetadata&iiurlwidth=' . $width
            . '&titles=' . rawurlencode($fileTitle);
        $json = $this->httpGetJson($url);
        if ($json === null) {
            return null;
        }
        foreach (($json['query']['pages'] ?? []) as $page) {
            $img = $this->parseImageInfo($page);
            if ($img !== null) {
                return $img;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function parseImageInfo(array $page): ?WikiImage
    {
        $info = $page['imageinfo'][0] ?? null;
        if (!is_array($info)) {
            return null;
        }
        $thumb = (string) ($info['thumburl'] ?? '');
        if ($thumb === '') {
            return null;
        }
        $meta    = $info['extmetadata'] ?? [];
        $title   = (string) ($page['title'] ?? '');
        $titleTrim = str_starts_with($title, 'File:') ? substr($title, 5) : $title;
        return new WikiImage(
            thumbUrl:       $thumb,
            descriptionUrl: (string) ($info['descriptionurl'] ?? ''),
            title:          mb_strimwidth($titleTrim, 0, 80, '…'),
            license:        (string) ($meta['LicenseShortName']['value'] ?? ''),
            author:         (string) ($meta['Artist']['value'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function httpGetJson(string $url): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,
                'header'        => [
                    'User-Agent: ' . self::USER_AGENT,
                    'Accept: application/json',
                ],
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Haversine — Distanz in km zwischen zwei Lat/Lon-Punkten.
     */
    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
