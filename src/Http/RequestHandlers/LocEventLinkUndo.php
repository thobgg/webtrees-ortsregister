<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Service\LocationEventLinker;
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
 * POST /tree/{tree}/orte/{place_id}/loc-events/undo
 *
 * Macht einen `loc_event_link` rückgängig: stellt je Datensatz den Vor-Stand
 * wieder her. Datensätze, die sich seither geändert haben, werden übersprungen
 * (nie spätere Edits überschreiben). Bindet an tree_id + operation.
 *
 * Body: log_id
 * Response: JSON { success, reverted, skipped, message }
 */
final class LocEventLinkUndo implements RequestHandlerInterface
{
    public function __construct(
        private readonly LocationEventLinker $linker,
        private readonly OperationBackup     $backup,
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

        $path = $this->backup->backupPathForUndo($logId, $tree->id(), 'loc_event_link');
        if ($path === null) {
            return $this->json(['success' => false, 'message' => 'Kein rückgängig machbarer Eintrag gefunden.'], StatusCodeInterface::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->linker->undo($tree, $path);
            $this->backup->markUndone($logId);
        } catch (Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => basename($e->getFile()) . ':' . $e->getLine(),
            ], StatusCodeInterface::STATUS_OK);
        }

        $skipped = $result['skipped'];
        $message = sprintf('%d Datensatz/-sätze zurückgesetzt.', (int) $result['reverted']);
        if ($skipped !== []) {
            $message .= sprintf(' %d übersprungen (seither geändert): %s', count($skipped), implode(', ', $skipped));
        }

        return $this->json([
            'success'  => true,
            'reverted' => $result['reverted'],
            'skipped'  => $skipped,
            'message'  => $message,
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
