<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Service\PlaceOperationService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /tree/{tree}/orte/rename/preview?place_id=X
 *
 * AJAX-Fragment fürs Bootstrap-Modal: Formular zum Umbenennen mit dem aktuellen
 * vollen PLAC-Wert vorbefüllt. Read-only (analyzeRename), kein Pending-Bypass.
 */
class RenameModalPage extends AbstractOrtsregisterHandler
{
    public function __construct(
        private readonly PlaceOperationService $service,
    ) {}

    protected function respond(
        ServerRequestInterface $request,
        ?Tree                  $tree,
    ): ResponseInterface {
        $params  = $request->getQueryParams();
        $placeId = (int) ($params['place_id'] ?? 0);

        if ($tree === null || $placeId <= 0) {
            return $this->fragment(
                '<div class="modal-body"><div class="alert alert-danger">'
                . 'Ungültige Place-ID für Umbenennen.</div></div>'
                . '<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button></div>',
                400,
            );
        }

        $info       = $this->service->analyzeRename($tree, $placeId);
        $autoAccept = Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) === '1';

        $html = view($this->viewName('rename-modal'), [
            'placeId'               => $placeId,
            'fullName'              => $info['fullName'],
            'affectedCount'         => $info['affectedCount'],
            'tree'                  => $tree,
            'autoAcceptEditsActive' => $autoAccept,
        ]);

        return $this->fragment($html, 200);
    }

    private function fragment(string $html, int $status): ResponseInterface
    {
        return Registry::responseFactory()->response(
            $html,
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
