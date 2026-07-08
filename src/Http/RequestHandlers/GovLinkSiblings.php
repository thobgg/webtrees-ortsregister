<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Repository\OrteRepository;
use Ortsregister\Service\GovLinkingService;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * POST /tree/{tree}/orte/{place_id}/gov-siblings
 *
 * Verknüpft mehrere gleichnamige Orte (Zeit-Varianten) in einem Schritt mit der
 * GOV-Kennung von {place_id} — die Sammel-Verknüpfung für Achse C, damit man nicht
 * jede Variante von Hand verknüpfen muss. Jede Verknüpfung übernimmt (wie einzeln)
 * auch die GOV-Koordinaten. Ändert KEINE PLAC.
 *
 * Body: sibling_ids[] (Auswahl der Kandidaten)
 * Response: JSON { success, linked, message }
 */
final class GovLinkSiblings implements RequestHandlerInterface
{
    public function __construct(
        private readonly GovLinkingService $linking,
        private readonly OrteRepository    $repository,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        if (!Auth::isEditor($tree, $user)) {
            return $this->json(['success' => false, 'message' => 'Keine Berechtigung.'], StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $placeId = (int) ($request->getAttribute('place_id') ?? 0);
        $govId   = $placeId > 0 ? $this->linking->getLinkedGovId($tree, $placeId) : null;
        if ($govId === null || $govId === '') {
            return $this->json(['success' => false, 'message' => 'Dieser Ort ist nicht mit GOV verknüpft.'], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        $body     = (array) $request->getParsedBody();
        $selected = array_map('intval', (array) ($body['sibling_ids'] ?? []));
        $selected = array_flip($selected); // schneller Lookup

        // NUR gültige Kandidaten verknüpfen — serverseitig neu bestimmt, nicht dem Client trauen.
        $valid = [];
        foreach ($this->repository->govVerknuepfungsKandidaten($tree, $placeId) as $cand) {
            if (isset($selected[$cand['id']])) {
                $valid[] = $cand['id'];
            }
        }
        if ($valid === []) {
            return $this->json(['success' => false, 'message' => 'Keine gültigen Orte zum Verknüpfen ausgewählt.'], StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
        }

        $linked = 0;
        $errors = [];
        foreach ($valid as $sid) {
            try {
                $this->linking->link($tree, $sid, $govId);
                $linked++;
            } catch (Throwable $e) {
                $errors[] = '#' . $sid . ': ' . $e->getMessage();
            }
        }

        $message = sprintf('%d Ort(e) mit GOV-Kennung %s verknüpft.', $linked, $govId);
        if ($errors !== []) {
            $message .= ' ⚠️ Fehler bei: ' . implode('; ', $errors);
        }

        return $this->json([
            'success' => $linked > 0,
            'linked'  => $linked,
            'message' => $message,
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
