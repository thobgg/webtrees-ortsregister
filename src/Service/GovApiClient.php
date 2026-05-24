<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Dto\GovObject;
use RuntimeException;

/**
 * HTTP-Client für die GOV-Webservice-API (gov.genealogy.net).
 *
 * Endpoints (verifiziert via Vesta-Gov4Webtrees-Source):
 *   GET https://gov.genealogy.net/api/getObject?itemId=<id>      → JSON
 *   GET https://gov.genealogy.net/api/checkObjectId?itemId=<id>  → 200|302|404
 *
 * GOV-Daten ändern sich selten — wir cachen aggressiv (7 Tage TTL).
 */
class GovApiClient
{
    private const BASE_URL    = 'https://gov.genealogy.net/api';
    private const TIMEOUT     = 10;     // Sekunden
    private const CACHE_TTL   = 604800; // 7 Tage
    private const USER_AGENT  = 'webtrees-ortsregister/0.1 (+https://github.com/thobgg/webtrees-ortsregister)';

    public function __construct(
        private readonly ApcuCacheService $cache,
    ) {}

    /**
     * Lädt ein vollständiges GOV-Object via /api/getObject.
     * Cached die Antwort. Wirft RuntimeException bei Netzwerk-/Parse-Fehlern.
     */
    public function getObject(string $govId): ?GovObject
    {
        $govId = $this->normalizeId($govId);
        if ($govId === '') {
            return null;
        }

        $cacheKey = 'gov:obj:' . $govId;
        return $this->cache->remember($cacheKey, function () use ($govId): ?GovObject {
            $json = $this->httpGetJson('/getObject?itemId=' . rawurlencode($govId));
            if ($json === null) {
                return null;
            }
            return $this->parseObject($govId, $json);
        }, self::CACHE_TTL);
    }

    /**
     * Prüft ob eine GOV-ID existiert. Liefert true bei HTTP 200/302, false bei 404.
     * Schnellerer Lookup als getObject() für reine Existenz-Checks.
     */
    public function checkObjectId(string $govId): bool
    {
        $govId = $this->normalizeId($govId);
        if ($govId === '') {
            return false;
        }
        $cacheKey = 'gov:check:' . $govId;
        return (bool) $this->cache->remember($cacheKey, function () use ($govId): bool {
            $status = $this->httpStatus('/checkObjectId?itemId=' . rawurlencode($govId));
            return $status === 200 || $status === 302;
        }, self::CACHE_TTL);
    }

    // ---------------------------------------------------------------
    // Intern: HTTP
    // ---------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function httpGetJson(string $path): ?array
    {
        $url = self::BASE_URL . $path;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,   // damit auch 4xx Body geliefert wird
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

    private function httpStatus(string $path): int
    {
        $url = self::BASE_URL . $path;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'HEAD',
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,
                'header'        => ['User-Agent: ' . self::USER_AGENT],
            ],
        ]);
        @get_headers($url, false, $ctx);
        // $http_response_header wird vom Stream-Wrapper gefüllt
        $headers = $http_response_header ?? [];
        if ($headers === []) {
            return 0;
        }
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $headers[0], $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    private function normalizeId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // Erlaubte Form: 'object_NNNNNN' oder 'adm_NNNNNN' (oder andere GOV-Prefixe)
        if (preg_match('/^[a-z][a-z_0-9]*_[0-9]+$/i', $raw) !== 1) {
            return '';
        }
        return $raw;
    }

    // ---------------------------------------------------------------
    // Intern: Response → DTO
    // ---------------------------------------------------------------

    /**
     * Wandelt die GOV-API-Antwort in ein DTO um.
     * GOV-Felder sind oft Arrays mit Zeit-Verläufen — wir nehmen erste Werte.
     *
     * @param array<string, mixed> $json
     */
    private function parseObject(string $govId, array $json): GovObject
    {
        // Namen: oft als Array von Objekten {value, lang, timeBegin?, timeEnd?}
        $namesByLang = [];
        $primaryName = '';
        $nameEntries = $this->ensureList($json['name'] ?? []);
        foreach ($nameEntries as $n) {
            if (is_array($n) && isset($n['value'])) {
                $lang = (string) ($n['lang'] ?? 'und');
                $val  = (string) $n['value'];
                if (!isset($namesByLang[$lang])) {
                    $namesByLang[$lang] = $val;
                }
                if ($primaryName === '') {
                    $primaryName = $val;
                }
            } elseif (is_string($n) && $primaryName === '') {
                $primaryName = $n;
            }
        }
        if (isset($namesByLang['deu']) && $primaryName !== $namesByLang['deu']) {
            $primaryName = $namesByLang['deu'];
        } elseif (isset($namesByLang['de']) && $primaryName !== $namesByLang['de']) {
            $primaryName = $namesByLang['de'];
        }

        // Types
        $typeIds = [];
        foreach ($this->ensureList($json['type'] ?? []) as $t) {
            if (is_array($t) && isset($t['value'])) {
                $typeIds[] = (string) $t['value'];
            } elseif (is_string($t)) {
                $typeIds[] = $t;
            }
        }

        // Position
        $lat = $lng = null;
        $pos = $json['position'] ?? null;
        if (is_array($pos)) {
            $lat = isset($pos['lat']) ? (float) $pos['lat'] : null;
            $lng = isset($pos['lon']) ? (float) $pos['lon'] : null;
        }

        // Hierarchie + Räumliche Zugehörigkeit
        $partOfIds    = $this->extractRefIds($json['part-of']    ?? $json['partOf']    ?? []);
        $locatedInIds = $this->extractRefIds($json['located-in'] ?? $json['locatedIn'] ?? []);

        // Externe URLs
        $externalUrls = [];
        foreach ($this->ensureList($json['external'] ?? $json['externalReference'] ?? []) as $e) {
            if (is_array($e) && isset($e['value'])) {
                $externalUrls[] = (string) $e['value'];
            } elseif (is_string($e)) {
                $externalUrls[] = $e;
            }
        }

        return new GovObject(
            govId:        $govId,
            primaryName:  $primaryName,
            namesByLang:  $namesByLang,
            typeIds:      $typeIds,
            latitude:     $lat,
            longitude:    $lng,
            partOfIds:    $partOfIds,
            locatedInIds: $locatedInIds,
            externalUrls: $externalUrls,
            rawJson:      $json,
        );
    }

    /**
     * GOV-Felder kommen mal als Liste, mal als einzelner Wert. Normalisieren.
     *
     * @param mixed $val
     * @return list<mixed>
     */
    private function ensureList(mixed $val): array
    {
        if (is_array($val) && array_is_list($val)) {
            return $val;
        }
        if ($val === null || $val === '') {
            return [];
        }
        return [$val];
    }

    /**
     * Extrahiert GOV-IDs aus part-of/located-in-Strukturen.
     * Erwartet Strukturen wie [{ ref: 'object_XYZ', timeBegin: ... }, ...]
     * oder schlichte Strings.
     *
     * @return list<string>
     */
    private function extractRefIds(mixed $val): array
    {
        $out = [];
        foreach ($this->ensureList($val) as $item) {
            if (is_array($item)) {
                $ref = $item['ref'] ?? $item['value'] ?? null;
                if (is_string($ref) && $ref !== '') {
                    $out[] = $ref;
                }
            } elseif (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }
}
