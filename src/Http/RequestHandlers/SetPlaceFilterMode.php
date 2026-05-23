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
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return $this->json(['success' => true, 'mode' => $mode], 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status): ResponseInterface
    {
        return Registry::responseFactory()->response(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
