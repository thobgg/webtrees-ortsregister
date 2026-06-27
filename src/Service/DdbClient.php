<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Dto\DdbItem;
use Ortsregister\Dto\DdbPlaceData;

/**
 * Client für die DDB-API (Deutsche Digitale Bibliothek).
 *
 * Endpoints:
 *   GET /search?query=...&rows=...&oauth_consumer_key=...
 *   GET /items/{id}?oauth_consumer_key=...   (für Vorschau-Bild-URLs)
 *
 * Strategie nach KIES-Vorbild: drei gestaffelte Suchen pro Ort
 *   (`<name> Pfarrbericht`, `<name> Pfarr`, `<name>`) — kirchliche/genealogische
 *   Quellen bevorzugen, dann allgemein.
 *
 * Bei leerem API-Key → leerer DTO, kein Netzwerk-Call.
 */
class DdbClient
{
    private const BASE_URL   = 'https://api.deutsche-digitale-bibliothek.de';
    private const TIMEOUT    = 4;
    private const USER_AGENT = 'webtrees-ortsregister/0.1 (+https://github.com/thobgg/webtrees-ortsregister)';

    public function __construct(
        private readonly ApcuCacheService $cache,
        private readonly string           $apiKey,
        private readonly int              $cacheTtl = 604800, // 7d
    ) {}

    /**
     * @param int $maxItems  max. Vorschau-Items in der Galerie
     */
    public function lookup(string $placeName, int $maxItems = 6): DdbPlaceData
    {
        $placeName = trim($placeName);
        if ($placeName === '' || $this->apiKey === '') {
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
            $resp = $this->search($query, 10, 'SORT_YEAR_ASC');
            if ($resp === null) {
                continue;
            }
            if ($total === 0) {
                $total = (int) ($resp['numberOfResults'] ?? 0);
            }
            $docs = $resp['results'][0]['docs'] ?? [];
            if (!is_array($docs)) {
                continue;
            }
            foreach ($docs as $doc) {
                if (!is_array($doc)) continue;
                $id = (string) ($doc['id'] ?? '');
                if ($id === '' || isset($seenIds[$id])) {
                    continue;
                }
                $label = (string) ($doc['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $seenIds[$id] = true;
                $thumb        = $this->itemThumbnail($id);
                $items[]      = new DdbItem(
                    id:           $id,
                    label:        $label,
                    subtitle:     (string) ($doc['subtitle'] ?? ''),
                    media:        (string) ($doc['media']    ?? ''),
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
    private function search(string $query, int $rows, ?string $sort = null): ?array
    {
        $params = [
            'query'              => $query,
            'rows'               => $rows,
            'oauth_consumer_key' => $this->apiKey,
        ];
        if ($sort !== null) {
            $params['sort'] = $sort;
        }
        return $this->httpGetJson(self::BASE_URL . '/search?' . http_build_query($params));
    }

    /**
     * Holt eine direkte Bild-URL aus dem /items/{id}-Endpoint. Erstes JPG aus
     * der Roh-Antwort gewinnt. Liefert null bei Fehler oder fehlendem Bild.
     */
    private function itemThumbnail(string $itemId): ?string
    {
        $url = self::BASE_URL . '/items/' . rawurlencode($itemId)
            . '?oauth_consumer_key=' . rawurlencode($this->apiKey);
        $body = $this->httpGetRaw($url);
        if ($body === null) {
            return null;
        }
        if (preg_match('#https://[^"\'<>\s]+\.(?:jpg|jpeg|JPG)#', $body, $m) === 1) {
            return $m[0];
        }
        return null;
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
