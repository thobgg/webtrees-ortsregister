<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Cache\ApcuCacheService;

/**
 * GOV-ID → GenWiki-Artikel (Issue #13).
 *
 * GenWiki führt einen Pseudo-Namensraum `GOV:<GOV-ID>`, dessen Seiten als
 * Weiterleitung auf den eigentlichen Ortsartikel zeigen. Die MediaWiki-API löst
 * das in einem Aufruf auf:
 *
 *   GET api.php?action=query&format=json&titles=GOV:<id>&redirects=1&prop=info&inprop=url
 *     → query.redirects[0].to = Artikelname   (Seite existiert)
 *     → query.pages[…].fullurl = kanonische Adresse
 *     → query.pages["-1"].missing             (keine Zuordnung hinterlegt)
 *
 * Die URL kommt bewusst aus `fullurl` statt aus selbst zusammengebautem Encoding:
 * MediaWiki kodiert Umlaute (Köln → K%C3%B6ln), lässt Klammern und Schrägstriche
 * aber stehen (Urbach_(Rems), Kreis_Ahaus/Ortschaften). Die API ist hier die
 * Autorität, nicht unsere Nachbildung ihrer Regeln.
 *
 * Quelle: discourse.genealogy.net/t/845352 (Clemens Draschba, BSchwend, EWinter),
 * eigene Stichproben 2026-08-01: KOLOLNJO30LW→Köln, URBACHJN48TT→Urbach (Rems),
 * PLEEIMJN48OX→Pleidelsheim, erfundene ID→missing.
 *
 * WICHTIG: „missing" heißt *keine Weiterleitung angelegt*, nicht „es gibt keinen
 * Artikel" — nicht zu jedem GOV-Objekt existiert eine GenWiki-Seite. Wir
 * verlinken deshalb ausschließlich, was die API auflöst; nichts wird geraten.
 *
 * Das HTML von wiki.genealogy.net liegt hinter Anubis (Proof-of-Work), `api.php`
 * dagegen antwortet ungehindert — verifiziert 2026-08-01.
 */
class GenWikiResolver
{
    private const API_URL    = 'https://wiki.genealogy.net/api.php';
    private const PAGE_BASE  = 'https://wiki.genealogy.net/';
    private const TIMEOUT    = 8;      // Sekunden
    private const USER_AGENT = 'webtrees-ortsregister (+https://github.com/thobgg/webtrees-ortsregister)';

    public function __construct(
        private readonly ApcuCacheService $cache,
        private readonly int              $cacheTtl = 604800,
    ) {}

    /**
     * Liefert Artikelnamen + URL, oder null wenn keine Zuordnung hinterlegt ist.
     *
     * @return array{title: string, url: string}|null
     */
    public function resolve(string $govId): ?array
    {
        $govId = $this->normalizeId($govId);
        if ($govId === '') {
            return null;
        }

        // Auch das negative Ergebnis wird gecacht — sonst fragt jede Ortsseite
        // ohne Zuordnung bei jedem Aufruf erneut an. `remember()` wertet null als
        // Cache-Miss, deshalb der Sentinel ['found' => false].
        $result = $this->cache->remember(
            'genwiki:gov:' . $govId,
            fn(): array => $this->lookup($govId),
            $this->cacheTtl,
        );

        if (($result['found'] ?? false) !== true) {
            return null;
        }
        return ['title' => $result['title'], 'url' => $result['url']];
    }

    /**
     * @return array{found: bool, title?: string, url?: string}
     */
    private function lookup(string $govId): array
    {
        $url = self::API_URL . '?' . http_build_query([
            'action'    => 'query',
            'format'    => 'json',
            'titles'    => 'GOV:' . $govId,
            'redirects' => '1',
            'prop'      => 'info',
            'inprop'    => 'url',
        ]);

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
            return ['found' => false];
        }
        try {
            $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['found' => false];
        }
        if (!is_array($json)) {
            return ['found' => false];
        }

        return $this->parse($json);
    }

    /**
     * @param array<string, mixed> $json
     * @return array{found: bool, title?: string, url?: string}
     */
    public function parse(array $json): array
    {
        $query = $json['query'] ?? null;
        if (!is_array($query)) {
            return ['found' => false];
        }

        // Nur ein aufgelöstes Redirect zählt als Zuordnung. Eine `GOV:`-Seite ohne
        // Weiterleitung (oder eine als `missing` gemeldete) verlinken wir nicht.
        $redirects = $query['redirects'] ?? [];
        if (!is_array($redirects) || $redirects === []) {
            return ['found' => false];
        }
        $to = $redirects[0]['to'] ?? null;
        if (!is_string($to) || trim($to) === '') {
            return ['found' => false];
        }
        $title = trim($to);

        // Die Zielseite muss als existierend gemeldet sein UND ihre kanonische URL
        // mitliefern. Ohne beides kein Link — lieber keiner als ein toter.
        foreach ((array) ($query['pages'] ?? []) as $page) {
            if (!is_array($page) || array_key_exists('missing', $page)) {
                continue;
            }
            if (($page['title'] ?? null) !== $title) {
                continue;
            }
            $url = $page['fullurl'] ?? $page['canonicalurl'] ?? null;
            if (is_string($url) && str_starts_with($url, self::PAGE_BASE)) {
                return ['found' => true, 'title' => $title, 'url' => $url];
            }
        }

        return ['found' => false];
    }

    private function normalizeId(string $raw): string
    {
        $raw = trim($raw);
        // Gleiche Schranke wie im GovApiClient: Buchstaben/Ziffern/Underscore.
        return preg_match('/^[A-Za-z0-9_]{3,40}$/', $raw) === 1 ? $raw : '';
    }
}
