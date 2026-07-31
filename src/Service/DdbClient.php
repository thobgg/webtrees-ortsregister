<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Dto\DdbItem;
use Ortsregister\Dto\DdbPlaceData;

/**
 * Client für die DDB-API 2.0 (Deutsche Digitale Bibliothek).
 *
 * Endpoints (alle ohne Authentifizierung):
 *   GET /2/search/index/search/select?q=...&rows=...&wt=json   (Solr-Durchgriff)
 *   GET /2/items/{id}/binaries                                 (Vorschau-Bild)
 *
 * Die alte API 1.0 verlangte einen `oauth_consumer_key`; deren Antrags-Formular
 * war ab 2026 defekt (Issue #18), und der DDB-Support verwies auf die 2.0 ohne
 * Key. Die 2.0 hat keinen `/search`-Endpoint mehr — stattdessen liegt der Solr-
 * Index unter `/search/index/{collection}/{requestHandler}` offen. Deshalb ist
 * die Antwortform hier Solr (`response.numFound` / `response.docs`) statt der
 * v1-Form (`numberOfResults` / `results[0].docs`).
 *
 * Strategie nach KIES-Vorbild: drei gestaffelte Suchen pro Ort
 *   (`<name> Pfarrbericht`, `<name> Pfarr`, `<name>`) — kirchliche/genealogische
 *   Quellen bevorzugen, dann allgemein.
 */
class DdbClient
{
    private const BASE_URL   = 'https://api.deutsche-digitale-bibliothek.de/2';
    private const TIMEOUT    = 4;
    private const USER_AGENT = 'webtrees-ortsregister/0.1 (+https://github.com/thobgg/webtrees-ortsregister)';

    public function __construct(
        private readonly ApcuCacheService $cache,
        private readonly int              $cacheTtl = 604800, // 7d
    ) {}

    /**
     * @param int $maxItems  max. Vorschau-Items in der Galerie
     */
    public function lookup(string $placeName, int $maxItems = 6): DdbPlaceData
    {
        $placeName = trim($placeName);
        if ($placeName === '') {
            return DdbPlaceData::empty();
        }
        $cacheKey = sprintf('ddb:%s:%d', md5($placeName), $maxItems);
        return $this->cache->remember($cacheKey, function () use ($placeName, $maxItems): DdbPlaceData {
            return $this->fetchAndBuild($placeName, $maxItems);
        }, $this->cacheTtl);
    }

    private function fetchAndBuild(string $placeName, int $maxItems): DdbPlaceData
    {
        $items   = [];
        $seenIds = [];
        $total   = 0;

        foreach ([
            $placeName . ' Pfarrbericht',
            $placeName . ' Pfarr',
            $placeName,
        ] as $query) {
            if (count($items) >= $maxItems) {
                break;
            }
            $resp = $this->search($query, 10);
            if ($resp === null) {
                continue;
            }
            if ($total === 0) {
                $total = (int) ($resp['response']['numFound'] ?? 0);
            }
            $docs = $resp['response']['docs'] ?? [];
            if (!is_array($docs)) {
                continue;
            }
            foreach ($docs as $doc) {
                if (!is_array($doc)) continue;
                $id = (string) ($doc['id'] ?? '');
                if ($id === '' || isset($seenIds[$id])) {
                    continue;
                }
                $label = self::firstString($doc['label'] ?? null);
                if ($label === '') {
                    continue;
                }
                $seenIds[$id] = true;
                // Nur Objekte mit Digitalisat haben Binaries — sonst sparen wir uns den Call.
                $thumb = ((string) ($doc['digitalisat'] ?? '')) === 'true'
                    ? $this->itemThumbnail($id)
                    : null;
                $items[] = new DdbItem(
                    id:           $id,
                    label:        $label,
                    // v2 kennt kein `subtitle`; die haltende Einrichtung ist der
                    // brauchbarste Ersatz für die zweite Zeile in der Galerie.
                    subtitle:     self::firstString($doc['provider'] ?? null),
                    // `type` ist ein Code ("mediatype_007"), der in der Platzhalter-
                    // Kachel unlesbar ist; `objecttype` ist bereits Klartext.
                    media:        self::firstString($doc['objecttype'] ?? null),
                    thumbnailUrl: $thumb,
                );
                if (count($items) >= $maxItems) {
                    break;
                }
            }
        }
        return new DdbPlaceData($total, $items);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function search(string $query, int $rows): ?array
    {
        // Solr-Durchgriff: Standard-Sortierung ist Relevanz. Die v1-Sortierung
        // `SORT_YEAR_ASC` ist hier keine gültige Solr-Syntax und quittiert mit 500.
        $params = [
            'q'    => $query,
            'rows' => $rows,
            'wt'   => 'json',
        ];
        return $this->httpGetJson(self::BASE_URL . '/search/index/search/select?' . http_build_query($params));
    }

    /**
     * Vorschau-Bild aus /items/{id}/binaries: erstes Binary mit Bild-Mimetype,
     * `primary` bevorzugt. Liefert null bei Fehler oder wenn es kein Bild gibt.
     */
    private function itemThumbnail(string $itemId): ?string
    {
        $resp = $this->httpGetJson(self::BASE_URL . '/items/' . rawurlencode($itemId) . '/binaries');
        $binaries = $resp['binary'] ?? null;
        if (!is_array($binaries)) {
            return null;
        }
        $fallback = null;
        foreach ($binaries as $binary) {
            if (!is_array($binary)) {
                continue;
            }
            $path = (string) ($binary['local_pathname'] ?? '');
            if ($path === '' || !str_starts_with((string) ($binary['mimetype'] ?? ''), 'image/')) {
                continue;
            }
            if (($binary['primary'] ?? false) === true) {
                return $path;
            }
            $fallback ??= $path;
        }
        return $fallback;
    }

    /**
     * Solr liefert die meisten Felder als Array. Nimmt den ersten Wert.
     */
    private static function firstString(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function httpGetJson(string $url): ?array
    {
        $body = $this->httpGetRaw($url);
        if ($body === null) {
            return null;
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private function httpGetRaw(string $url): ?string
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
        return $body !== false ? $body : null;
    }
}
