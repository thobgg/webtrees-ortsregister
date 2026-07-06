<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Service\LocationWriter;
use Ortsregister\Service\OperationBackup;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * POST /tree/{tree}/orte/{place_id}/loc-write/undo
 *
 * Macht einen `loc_write` rückgängig: create → Record löschen, update → alten
 * Record-Stand zurückschreiben. Bindet an tree_id + operation (kein Undo fremder Ops).
 *
 * Body: log_id
 * Response: JSON { success, action, xref, message }
 */
final class LocWriteUndo implements RequestHandlerInterface
{
    public function __construct(
        private readonly LocationWriter  $writer,
        private readonly OperationBackup $backup,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        if (!Auth::isEditor($tree, $user)) {
            return $this->json(['success' => false, 'message' => 'Keine Berechtigung.'], StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $body  = (array) $request->getParsedBody();
        $logId = (int) ($body['log_id'] ?? 0);
        if ($logId <= 0) {
            return $this->json(['success' => false, 'message' => 'Ungültige log_id.'], StatusCodeInterface::STATUS_BAD_REQUEST);
        }

        $path = $this->backup->backupPathForUndo($logId, $tree->id(), 'loc_write');
        if ($path === null) {
            return $this->json(['success' => false, 'message' => 'Kein rückgängig machbarer Eintrag gefunden.'], StatusCodeInterface::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->writer->undo($tree, $path);
            $this->backup->markUndone($logId);
        } catch (Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => basename($e->getFile()) . ':' . $e->getLine(),
            ], StatusCodeInterface::STATUS_OK);
        }

        return $this->json([
            'success' => true,
            'action'  => $result['action'],
            'xref'    => $result['xref'],
            'message' => sprintf('_LOC-Schreibaktion rückgängig gemacht (@%s@).', (string) $result['xref']),
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
