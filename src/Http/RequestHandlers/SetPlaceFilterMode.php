<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Repository\OrteRepository;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * POST /tree/{tree}/orte/filter-mode
 *
 * Speichert die User-Preference für den Hierarchie-Filter
 * (all / leaves) und gibt JSON zurück.
 *
 * Body: mode = 'all' | 'leaves'
 * Response: { success, mode }
 */
class SetPlaceFilterMode implements RequestHandlerInterface
{
    public function __construct(
        private readonly ApcuCacheService $cache,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $mode = (string) ($body['mode'] ?? OrteRepository::MODE_ALL);

        // Whitelist-Validierung gegen ungültige Werte
        $mode = $mode === OrteRepository::MODE_LEAVES
            ? OrteRepository::MODE_LEAVES
            : OrteRepository::MODE_ALL;

        try {
            Auth::user()->setPreference(OrtePage::PREF_PLACE_FILTER_MODE, $mode);
            // Cache invalidieren, sonst zeigt die nächste Anfrage alte Liste
            $this->cache->flush();
        } catch (Throwable $e) {
            // Status 200 mit success:false — sonst HTML-Fehlerseite → „JSON.parse".
            return $this->json(['success' => false, 'message' => $e->getMessage()], 200);
        }

        return $this->json(['success' => true, 'mode' => $mode], 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status): ResponseInterface
    {
        return Registry::responseFactory()->response(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
