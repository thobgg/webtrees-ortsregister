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
 * POST /tree/{tree}/orte/{place_id}/notizen
 *
 * Body: markdown (string), mtime (int — expected, 0 für neu)
 * Response: JSON {success, mtime, html} | {success:false, message}
 */
final class PlaceNotesSave implements RequestHandlerInterface
{
    public function __construct(
        private readonly PlaceNotesService $notesService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        // ACL: Editor+ dürfen Notizen schreiben (Member sehen sie nur)
        if (!Auth::isEditor($tree, $user)) {
            return $this->json(['success' => false, 'message' => 'Keine Berechtigung.'], StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $placeId = (int) ($request->getAttribute('place_id') ?? 0);
        if ($placeId <= 0) {
            return $this->json(['success' => false, 'message' => 'Ungültige place_id.'], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        // Ortsname aus places-Tabelle
        $row = DB::table('places')
            ->where('p_id',   '=', $placeId)
            ->where('p_file', '=', $tree->id())
            ->select(['p_place'])
            ->first();
        if ($row === null) {
            return $this->json(['success' => false, 'message' => 'Ort nicht gefunden.'], StatusCodeInterface::STATUS_NOT_FOUND);
        }
        $placeName = (string) $row->p_place;

        $body          = (array) $request->getParsedBody();
        $markdown      = (string) ($body['markdown'] ?? '');
        $expectedMtime = (int)    ($body['mtime']    ?? 0);
        $filename      = (string) ($body['filename'] ?? 'notes.md');

        if (!$this->notesService->isValidFilename($filename)) {
            return $this->json(['success' => false, 'message' => 'Ungültiger Filename.'], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        try {
            $newMtime = $this->notesService->save($tree, $placeName, $markdown, $expectedMtime, $filename);
            return $this->json([
                'success'  => true,
                'mtime'    => $newMtime,
                'html'     => $this->notesService->render($markdown, $tree),
                'markdown' => $markdown,
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
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
