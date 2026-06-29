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
 * POST /tree/{tree}/orte/merge/execute
 *
 * Body: src, dst, resolutions[<tag>] = source|target|drop
 * Response: JSON { success, modified, backup, log_id, message }
 *
 * Pending-Changes-Bypass via PREF_AUTO_ACCEPT_EDITS-Check im Service.
 */
class MergeExecute implements RequestHandlerInterface
{
    public function __construct(
        private readonly PlaceOperationService $service,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $this->safeTree($request);
        if ($tree === null) {
            return $this->json(['success' => false, 'message' => 'Tree nicht erkannt.'], 400);
        }
        if (!Auth::isMember($tree)) {
            return $this->json(['success' => false, 'message' => 'Nicht authentifiziert.'], 401);
        }

        $body  = $request->getParsedBody();
        $srcId = (int) ($body['src'] ?? 0);
        $dstId = (int) ($body['dst'] ?? 0);
        $resolutions = (array) ($body['resolutions'] ?? []);

        if ($srcId <= 0 || $dstId <= 0 || $srcId === $dstId) {
            return $this->json(['success' => false, 'message' => 'Ungültige Place-IDs.'], 422);
        }

        try {
            $result = $this->service->executeMerge($tree, $srcId, $dstId, $resolutions);
        } catch (Throwable $e) {
            $this->logExceptionToFile($e, ['src' => $srcId, 'dst' => $dstId]);
            // Status 200 mit success:false — sonst ersetzt webtrees eine 500er
            // durch eine HTML-Fehlerseite (Frontend sieht „JSON.parse").
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => basename($e->getFile()) . ':' . $e->getLine(),
            ], 200);
        }

        $message = sprintf(
            '%d Records umgeschrieben. Backup: %s',
            $result->modifiedRecords,
            basename($result->backupPath),
        );
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
    }

    private function safeTree(ServerRequestInterface $request): ?Tree
    {
        try {
            return Validator::attributes($request)->tree();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Schreibt Exception als Klartext in backups/exec_error.log.
     * Diagnose-Hilfe falls webtrees-Middleware die Exception sonst abfängt.
     *
     * @param array<string, mixed> $context
     */
    private function logExceptionToFile(Throwable $e, array $context = []): void
    {
        $logDir = __DIR__ . '/../../../backups';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $entry = sprintf(
            "[%s] %s: %s in %s:%d\nContext: %s\nTrace:\n%s\n%s\n",
            date('c'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            json_encode($context),
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
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
