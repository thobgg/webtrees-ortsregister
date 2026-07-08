<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Repository\OrteRepository;
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
 * POST /tree/{tree}/orte/{place_id}/loc-events
 *
 * Setzt den Ereignis→Ort-Zeiger `3 _LOC @x@` (W2) auf alle passenden INDI/FAM.
 * Plant serverseitig neu (traut dem Client nicht), schreibt additiv, sichert + loggt
 * für Undo. Braucht einen vorhandenen `_LOC` (sonst NO_LOC → erst W1).
 *
 * Response: JSON { success, action, xref, records, pointers, log_id, message }
 */
final class LocEventLinkExecute implements RequestHandlerInterface
{
    public function __construct(
        private readonly LocationEventLinker $linker,
        private readonly OrteRepository      $repository,
        private readonly OperationBackup     $backup,
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

        try {
            $path = $this->repository->vollerPfad($tree, $placeId) ?? $ort->name;
            $plan = $this->linker->plan($tree, $placeId, $ort->name, $path);

            if ($plan->action === $plan::ACTION_NO_LOC) {
                return $this->json([
                    'success' => false,
                    'message' => 'Für diesen Ort gibt es noch keinen _LOC-Record — bitte zuerst die _LOC-Identität schreiben (W1).',
                ], StatusCodeInterface::STATUS_OK);
            }
            if ($plan->action === $plan::ACTION_AMBIGUOUS) {
                return $this->json([
                    'success'    => false,
                    'message'    => 'Mehrere _LOC-Records mit diesem Namen — bitte erst eindeutig machen.',
                    'candidates' => $plan->candidates,
                ], StatusCodeInterface::STATUS_OK);
            }
            if (!$plan->willWrite()) {
                return $this->json([
                    'success' => true,
                    'action'  => $plan::ACTION_NONE,
                    'message' => $plan->alreadyLinked > 0
                        ? sprintf('Nichts zu tun — alle %d Ereignisse am Ort tragen den _LOC-Zeiger schon.', $plan->alreadyLinked)
                        : 'Nichts zu tun — keine Ereignisse an diesem Ort gefunden.',
                ], StatusCodeInterface::STATUS_OK);
            }

            $result = $this->linker->execute($tree, $placeId, $ort->name, $path);
            $logId  = $this->backup->log($tree->id(), 'loc_event_link', $placeId, Auth::id(), (string) $result['backup_path']);
        } catch (Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => basename($e->getFile()) . ':' . $e->getLine(),
            ], StatusCodeInterface::STATUS_OK);
        }

        return $this->json([
            'success'  => true,
            'action'   => $plan::ACTION_LINK,
            'xref'     => $result['xref'],
            'records'  => $result['records'],
            'pointers' => $result['pointers'],
            'log_id'   => $logId,
            'message'  => sprintf(
                '%d Ereignis(se) in %d Datensatz/-sätzen mit _LOC @%s@ verknüpft.',
                (int) $result['pointers'],
                (int) $result['records'],
                (string) $result['xref'],
            ),
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
