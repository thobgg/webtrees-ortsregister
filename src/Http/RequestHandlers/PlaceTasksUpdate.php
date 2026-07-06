<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Dto\PlaceTask;
use Ortsregister\Service\PlaceTasksService;
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
 * POST /tree/{tree}/orte/{place_id}/aufgaben
 *
 * Body: action=add|toggle|edit|delete, id?, text?
 * Response: JSON {success, tasks:[...], counts:{open,done}} oder {success:false, message}
 */
final class PlaceTasksUpdate implements RequestHandlerInterface
{
    public function __construct(
        private readonly PlaceTasksService $tasksService,
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
        $id     = (string) ($body['id']     ?? '');
        $text   = (string) ($body['text']   ?? '');

        try {
            switch ($action) {
                case 'add':
                    $this->tasksService->add($tree, $placeName, $text, (string) $user->realName());
                    break;
                case 'toggle':
                    if ($id === '') throw new \RuntimeException('Keine ID.');
                    $this->tasksService->toggle($tree, $placeName, $id);
                    break;
                case 'edit':
                    if ($id === '') throw new \RuntimeException('Keine ID.');
                    $this->tasksService->updateText($tree, $placeName, $id, $text);
                    break;
                case 'delete':
                    if ($id === '') throw new \RuntimeException('Keine ID.');
                    $this->tasksService->delete($tree, $placeName, $id);
                    break;
                default:
                    return $this->json(['success' => false, 'message' => 'Unbekannte Action: ' . $action], StatusCodeInterface::STATUS_BAD_REQUEST);
            }
        } catch (Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], StatusCodeInterface::STATUS_CONFLICT);
        }

        $tasks = $this->tasksService->read($tree, $placeName);
        $open  = 0;
        $done  = 0;
        foreach ($tasks as $t) {
            $t->isOpen() ? $open++ : $done++;
        }
        return $this->json([
            'success' => true,
            'tasks'   => array_map(static fn(PlaceTask $t) => $t->toArray(), $tasks),
            'counts'  => ['open' => $open, 'done' => $done],
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
