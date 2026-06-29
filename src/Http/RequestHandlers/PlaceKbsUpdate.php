<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Dto\PlaceKb;
use Ortsregister\Service\PlaceKbListService;
use Ortsregister\Service\PlaceNotesService;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * POST /tree/{tree}/orte/{place_id}/kbs
 *
 * Body: action=add|edit|delete|read-log|save-log + Parameter
 * Response: JSON
 */
final class PlaceKbsUpdate implements RequestHandlerInterface
{
    public function __construct(
        private readonly PlaceKbListService $kbService,
        private readonly PlaceNotesService  $notesService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        if (!Auth::isEditor($tree, $user)) {
            return $this->json(['success' => false, 'message' => 'Keine Berechtigung.'], StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $placeId = (int) ($request->getAttribute('place_id') ?? 0);
        if ($placeId <= 0) {
            return $this->json(['success' => false, 'message' => 'Ungültige place_id.'], StatusCodeInterface::STATUS_BAD_REQUEST);
        }
        $row = DB::table('places')
            ->where('p_id',   '=', $placeId)
            ->where('p_file', '=', $tree->id())
            ->select(['p_place'])
            ->first();
        if ($row === null) {
            return $this->json(['success' => false, 'message' => 'Ort nicht gefunden.'], StatusCodeInterface::STATUS_NOT_FOUND);
        }
        $placeName = (string) $row->p_place;

        $body   = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');

        try {
            switch ($action) {
                case 'add':
                    $this->kbService->add(
                        $tree, $placeName,
                        (string) ($body['title'] ?? ''),
                        (string) ($body['type']  ?? 'sonstige'),
                        $this->intOrNull($body['year_from']   ?? null),
                        $this->intOrNull($body['year_to']     ?? null),
                        $this->strOrNull($body['archion_url'] ?? null),
                        $this->strOrNull($body['sour_xref']   ?? null),
                    );
                    return $this->listResponse($tree, $placeName);

                case 'edit':
                    $id = (string) ($body['id'] ?? '');
                    if ($id === '') throw new \RuntimeException('Keine ID.');
                    $this->kbService->update(
                        $tree, $placeName, $id,
                        (string) ($body['title'] ?? ''),
                        (string) ($body['type']  ?? 'sonstige'),
                        $this->intOrNull($body['year_from']   ?? null),
                        $this->intOrNull($body['year_to']     ?? null),
                        $this->strOrNull($body['archion_url'] ?? null),
                        $this->strOrNull($body['sour_xref']   ?? null),
                    );
                    return $this->listResponse($tree, $placeName);

                case 'delete':
                    $id = (string) ($body['id'] ?? '');
                    if ($id === '') throw new \RuntimeException('Keine ID.');
                    $this->kbService->delete($tree, $placeName, $id);
                    return $this->listResponse($tree, $placeName);

                case 'read-log':
                    $id = (string) ($body['id'] ?? '');
                    if ($id === '') throw new \RuntimeException('Keine ID.');
                    $md   = $this->kbService->readLogbook($tree, $placeName, $id);
                    $html = $this->notesService->render($md, $tree);
                    return $this->json([
                        'success'  => true,
                        'markdown' => $md,
                        'html'     => $html,
                    ]);

                case 'save-log':
                    $id = (string) ($body['id'] ?? '');
                    if ($id === '') throw new \RuntimeException('Keine ID.');
                    $md = (string) ($body['markdown'] ?? '');
                    $this->kbService->saveLogbook($tree, $placeName, $id, $md);
                    $html = $this->notesService->render($md, $tree);
                    return $this->json([
                        'success'  => true,
                        'markdown' => $md,
                        'html'     => $html,
                    ]);

                default:
                    return $this->json(['success' => false, 'message' => 'Unbekannte Action: ' . $action], StatusCodeInterface::STATUS_BAD_REQUEST);
            }
        } catch (Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], StatusCodeInterface::STATUS_CONFLICT);
        }
    }

    private function listResponse($tree, string $placeName): ResponseInterface
    {
        $kbs = $this->kbService->read($tree, $placeName);
        // Sortierung: nach year_from aufsteigend (null ans Ende)
        usort($kbs, function (PlaceKb $a, PlaceKb $b) {
            $av = $a->yearFrom ?? PHP_INT_MAX;
            $bv = $b->yearFrom ?? PHP_INT_MAX;
            return $av <=> $bv;
        });
        $hasLog = [];
        foreach ($kbs as $kb) {
            $hasLog[$kb->id] = $this->kbService->readLogbook($tree, $placeName, $kb->id) !== '';
        }
        return $this->json([
            'success'  => true,
            'kbs'      => array_map(fn(PlaceKb $k) => $k->toArray() + ['has_log' => $hasLog[$k->id]], $kbs),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = StatusCodeInterface::STATUS_OK): ResponseInterface
    {
        return Registry::responseFactory()->response(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /** @param mixed $v */
    private function intOrNull($v): ?int
    {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        return (int) $v;
    }

    /** @param mixed $v */
    private function strOrNull($v): ?string
    {
        if (!is_string($v)) return null;
        $s = trim($v);
        return $s === '' ? null : $s;
    }
}
