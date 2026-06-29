<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Service\PlaceOperationService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * POST /tree/{tree}/orte/merge/undo
 *
 * Body: log_id (Eintrag aus ortsregister_merge_log)
 * Response: JSON { success, restored, message }
 *
 * Spielt einen Merge über sein Backup zurück und markiert den Log-Eintrag als
 * 'undone'. Pending-Changes-Bypass via PREF_AUTO_ACCEPT_EDITS-Check im Service.
 */
class MergeUndo implements RequestHandlerInterface
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
        $logId = (int) ($body['log_id'] ?? 0);
        if ($logId <= 0) {
            return $this->json(['success' => false, 'message' => 'Ungültige Log-ID.'], 422);
        }

        $row = DB::table('ortsregister_merge_log')
            ->where('id', '=', $logId)
            ->where('tree_id', '=', $tree->id())
            ->first();
        if ($row === null) {
            return $this->json(['success' => false, 'message' => 'Merge-Eintrag nicht gefunden.'], 404);
        }
        if (($row->status ?? '') === 'undone') {
            return $this->json(['success' => false, 'message' => 'Dieser Merge wurde bereits rückgängig gemacht.'], 409);
        }

        try {
            $restored = $this->service->undoMerge($tree, (string) $row->backup_path);
        } catch (Throwable $e) {
            // Status 200 mit success:false — sonst HTML-Fehlerseite → „JSON.parse".
            return $this->json(['success' => false, 'message' => $e->getMessage()], 200);
        }

        DB::table('ortsregister_merge_log')
            ->where('id', '=', $logId)
            ->where('tree_id', '=', $tree->id())
            ->update(['status' => 'undone']);

        return $this->json([
            'success'  => true,
            'restored' => $restored,
            'message'  => sprintf('Merge rückgängig gemacht. %d Records wiederhergestellt.', $restored),
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
