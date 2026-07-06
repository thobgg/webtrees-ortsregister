<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Dto\LocWritePlan;
use Ortsregister\Repository\OrteRepository;
use Ortsregister\Service\GovLinkingService;
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
 * POST /tree/{tree}/orte/{place_id}/loc-write
 *
 * Schreibt/aktualisiert den `_LOC`-Identitäts-Record (W1). Plant serverseitig neu
 * (traut dem Client nicht), schreibt additiv, sichert + loggt für Undo.
 *
 * Body: target_xref (optional — Auflösung des mehrdeutigen Falls)
 * Response: JSON { success, action, xref, log_id, conflicts, message }
 */
final class LocWriteExecute implements RequestHandlerInterface
{
    public function __construct(
        private readonly LocationWriter    $writer,
        private readonly OrteRepository    $repository,
        private readonly GovLinkingService $govLinking,
        private readonly OperationBackup   $backup,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        if (!Auth::isEditor($tree, $user)) {
            return $this->json(['success' => false, 'message' => 'Keine Berechtigung.'], StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $placeId = (int) ($request->getAttribute('place_id') ?? 0);
        $ort     = $placeId > 0 ? $this->repository->findeOrtById($tree, $placeId) : null;
        if ($ort === null) {
            return $this->json(['success' => false, 'message' => 'Ort nicht gefunden.'], StatusCodeInterface::STATUS_NOT_FOUND);
        }

        $body   = (array) $request->getParsedBody();
        $target = trim((string) ($body['target_xref'] ?? ''));

        try {
            $govId = $this->govLinking->getLinkedGovId($tree, $placeId);
            $plan  = $target !== ''
                ? $this->writer->planForTarget($tree, $placeId, $ort->name, $govId, $ort->breitengrad, $ort->laengengrad, $target)
                : $this->writer->plan($tree, $placeId, $ort->name, $govId, $ort->breitengrad, $ort->laengengrad);

            if ($plan->action === LocWritePlan::ACTION_AMBIGUOUS) {
                return $this->json([
                    'success'    => false,
                    'message'    => 'Mehrere _LOC-Records mit diesem Namen — bitte einen auswählen.',
                    'candidates' => $plan->candidates,
                ], StatusCodeInterface::STATUS_OK);
            }

            if (!$plan->willWrite()) {
                return $this->json([
                    'success'   => true,
                    'action'    => LocWritePlan::ACTION_NONE,
                    'conflicts' => $plan->conflicts,
                    'message'   => $plan->conflicts === []
                        ? 'Nichts zu schreiben — die Identität ist bereits vollständig.'
                        : 'Nichts geschrieben — nur abweichende Bestandswerte (siehe Konflikte).',
                ], StatusCodeInterface::STATUS_OK);
            }

            $result = $this->writer->execute($tree, $plan);
            $logId  = $this->backup->log($tree->id(), 'loc_write', $placeId, Auth::id(), (string) $result['backup_path']);
        } catch (Throwable $e) {
            // 200 mit success:false — sonst ersetzt webtrees die 500 durch HTML.
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => basename($e->getFile()) . ':' . $e->getLine(),
            ], StatusCodeInterface::STATUS_OK);
        }

        $xref    = (string) $result['xref'];
        $message = $plan->action === LocWritePlan::ACTION_CREATE
            ? sprintf('_LOC-Record @%s@ angelegt.', $xref)
            : sprintf('_LOC-Record @%s@ ergänzt.', $xref);
        if ($plan->conflicts !== []) {
            $message .= ' ⚠️ ' . implode(' ', $plan->conflicts);
        }

        return $this->json([
            'success'   => true,
            'action'    => $plan->action,
            'xref'      => $xref,
            'log_id'    => $logId,
            'conflicts' => $plan->conflicts,
            'message'   => $message,
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
