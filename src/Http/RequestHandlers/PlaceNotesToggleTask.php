<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

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
 * POST /tree/{tree}/orte/{place_id}/notizen/toggle
 *
 * Body: filename, task_index, checked (0/1)
 * Toggelt eine GFM-Task-List-Checkbox in der Markdown-Quelle und liefert
 * das neu gerenderte HTML + neuen mtime zurück.
 */
final class PlaceNotesToggleTask implements RequestHandlerInterface
{
    public function __construct(
        private readonly PlaceNotesService $notesService,
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

        $body      = (array) $request->getParsedBody();
        $filename  = (string) ($body['filename']   ?? 'notes.md');
        $taskIndex = (int)    ($body['task_index'] ?? -1);
        $checked   = ((string) ($body['checked'] ?? '0')) === '1';

        if (!$this->notesService->isValidFilename($filename) || $taskIndex < 0) {
            return $this->json(['success' => false, 'message' => 'Ungültige Parameter.'], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        try {
            $current  = $this->notesService->read($tree, $placeName, $filename);
            $newMd    = $this->notesService->toggleTaskInMarkdown($current->markdown, $taskIndex, $checked);
            // mtime-Lock — wir nutzen current->mtime als expected, um spätere Änderungen nicht zu killen
            $newMtime = $this->notesService->save($tree, $placeName, $newMd, $current->mtime, $filename);
            return $this->json([
                'success'  => true,
                'mtime'    => $newMtime,
                'html'     => $this->notesService->render($newMd, $tree),
                'markdown' => $newMd,
                'filename' => $filename,
            ]);
        } catch (Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], StatusCodeInterface::STATUS_CONFLICT);
        }
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
}
