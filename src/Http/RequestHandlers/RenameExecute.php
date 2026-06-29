<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Service\PlaceOperationService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * POST /tree/{tree}/orte/rename/execute
 *
 * Body: src (place_id), new_value (neuer voller PLAC-Wert)
 * Response: JSON { success, modified, backup, log_id, warnings, message }
 *
 * Reine Hygiene-Op (Tippfehler korrigieren), ohne Ziel-Ort. Existiert der neue
 * Name bereits → Service wirft (zur Merge-Route lenken).
 *
 * Der GESAMTE Handler ist try/catch-umschlossen + datei-geloggt — damit auch
 * Fehler beim Antwort-Bauen (z.B. json_encode) als JSON zurückkommen statt als
 * HTML-Fehlerseite (die das Frontend als „JSON.parse"-Fehler sieht).
 */
class RenameExecute implements RequestHandlerInterface
{
    public function __construct(
        private readonly PlaceOperationService $service,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $tree = $this->safeTree($request);
            if ($tree === null) {
                return $this->json(['success' => false, 'message' => 'Tree nicht erkannt.'], 400);
            }
            if (!Auth::isMember($tree)) {
                return $this->json(['success' => false, 'message' => 'Nicht authentifiziert.'], 401);
            }

            $body     = (array) $request->getParsedBody();
            $srcId    = (int) ($body['src'] ?? 0);
            $newValue = trim((string) ($body['new_value'] ?? ''));

            if ($srcId <= 0) {
                return $this->json(['success' => false, 'message' => 'Ungültige Place-ID.'], 422);
            }
            if ($newValue === '') {
                return $this->json(['success' => false, 'message' => 'Neuer Name fehlt.'], 422);
            }

            $result = $this->service->executeRename($tree, $srcId, $newValue);

            $message = sprintf('Umbenannt. %d Records umgeschrieben.', $result->modifiedRecords);
            if ($result->warnings !== []) {
                $message .= ' ⚠️ ' . implode(' ', $result->warnings);
            }

            return $this->json([
                'success'  => true,
                'modified' => $result->modifiedRecords,
                'backup'   => basename($result->backupPath),
                'log_id'   => $result->logId,
                'warnings' => $result->warnings,
                'message'  => $message,
            ], 200);
        } catch (Throwable $e) {
            $this->logExceptionToFile($e);
            // Status 200 mit success:false — sonst ersetzt webtrees eine 500er
            // ggf. durch eine HTML-Fehlerseite (Frontend sieht „JSON.parse").
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => basename($e->getFile()) . ':' . $e->getLine(),
            ], 200);
        }
    }

    private function safeTree(ServerRequestInterface $request): ?Tree
    {
        try {
            return Validator::attributes($request)->tree();
        } catch (Throwable) {
            return null;
        }
    }

    private function logExceptionToFile(Throwable $e): void
    {
        $logDir = __DIR__ . '/../../../backups';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $entry = sprintf(
            "[%s] RENAME %s: %s in %s:%d\nTrace:\n%s\n%s\n",
            date('c'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
            str_repeat('-', 80),
        );
        @file_put_contents($logDir . '/exec_error.log', $entry, FILE_APPEND);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status): ResponseInterface
    {
        return Registry::responseFactory()->response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
