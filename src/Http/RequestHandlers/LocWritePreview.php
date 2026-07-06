<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Repository\OrteRepository;
use Ortsregister\Service\GovLinkingService;
use Ortsregister\Service\LocationWriter;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * GET /tree/{tree}/orte/{place_id}/loc-write/preview
 *
 * Rein lesend: berechnet, WAS der Writer täte (kein Schreiben). Speist das
 * Bestätigungs-Modal — nie still schreiben.
 *
 * Response: JSON { success, action, target_xref, gedcom, conflicts, candidates, place_name }
 */
final class LocWritePreview implements RequestHandlerInterface
{
    public function __construct(
        private readonly LocationWriter    $writer,
        private readonly OrteRepository    $repository,
        private readonly GovLinkingService $govLinking,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        if (!Auth::isEditor($tree, $user)) {
            return $this->json(['success' => false, 'message' => 'Keine Berechtigung.'], StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $placeId = (int) ($request->getAttribute('place_id') ?? 0);
        $ort     = $placeId > 0 ? $this->repository->findeOrtById($tree, $placeId) : null;
        if ($ort === null) {
            return $this->json(['success' => false, 'message' => 'Ort nicht gefunden.'], StatusCodeInterface::STATUS_NOT_FOUND);
        }

        try {
            $govId = $this->govLinking->getLinkedGovId($tree, $placeId);
            $plan  = $this->writer->plan($tree, $placeId, $ort->name, $govId, $ort->breitengrad, $ort->laengengrad);
        } catch (Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], StatusCodeInterface::STATUS_OK);
        }

        return $this->json([
            'success'     => true,
            'action'      => $plan->action,
            'target_xref' => $plan->targetXref,
            'gedcom'      => $plan->gedcomPreview(),
            'conflicts'   => $plan->conflicts,
            'candidates'  => $plan->candidates,
            'place_name'  => $plan->placeName,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = StatusCodeInterface::STATUS_OK): ResponseInterface
    {
        return Registry::responseFactory()->response(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
