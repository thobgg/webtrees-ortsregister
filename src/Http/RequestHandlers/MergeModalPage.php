<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Service\PlaceOperationService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /tree/{tree}/orte/merge/preview?src=X&dst=Y
 *
 * AJAX-Endpoint: liefert HTML-Fragment für das Bootstrap-Modal.
 * Service-Call ist read-only (analyzeMerge), kein Pending-Bypass nötig.
 */
class MergeModalPage extends AbstractOrtsregisterHandler
{
    protected string $layout = 'layouts/ajax';

    public function __construct(
        private readonly PlaceOperationService $service,
    ) {}

    protected function respond(
        ServerRequestInterface $request,
        ?Tree                  $tree,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $srcId  = (int) ($params['src'] ?? 0);
        $dstId  = (int) ($params['dst'] ?? 0);

        if ($tree === null || $srcId <= 0 || $dstId <= 0 || $srcId === $dstId) {
            return \Fisharebest\Webtrees\Registry::responseFactory()->response(
                '<div class="modal-body"><div class="alert alert-danger">'
                . 'Ungültige Place-IDs für Merge.</div></div>'
                . '<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button></div>',
                400,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $analysis = $this->service->analyzeMerge($tree, $srcId, $dstId);

        $autoAccept = Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) === '1';

        return $this->viewResponse($this->viewName('merge-modal'), [
            'analysis'              => $analysis,
            'tree'                  => $tree,
            'autoAcceptEditsActive' => $autoAccept,
        ]);
    }
}
