<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Repository\OrteRepository;
use Ortsregister\Service\LocationEventLinker;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * GET /tree/{tree}/orte/{place_id}/loc-events/preview
 *
 * Rein lesend: berechnet, WELCHE Ereignisse den `_LOC`-Zeiger (W2) bekämen. Speist
 * das Bestätigungs-Modal — nie still schreiben.
 *
 * Response: JSON { success, action, loc_xref, pointers, records, already_linked, targets, candidates, place_name }
 */
final class LocEventLinkPreview implements RequestHandlerInterface
{
    public function __construct(
        private readonly LocationEventLinker $linker,
        private readonly OrteRepository      $repository,
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
            $path = $this->repository->vollerPfad($tree, $placeId) ?? $ort->name;
            $plan = $this->linker->plan($tree, $placeId, $ort->name, $path);
        } catch (Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], StatusCodeInterface::STATUS_OK);
        }

        return $this->json([
            'success'        => true,
            'action'         => $plan->action,
            'loc_xref'       => $plan->locXref,
            'pointers'       => $plan->pointersToAdd,
            'records'        => $plan->recordCount(),
            'already_linked' => $plan->alreadyLinked,
            'targets'        => $plan->targets,
            'candidates'     => $plan->candidates,
            'place_name'     => $plan->placeName,
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
