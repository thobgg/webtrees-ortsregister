<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Service\GovApiClient;
use Ortsregister\Service\GovLinkingService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * GET  /tree/{tree}/orte/gov?place_id=N      → Modal-Fragment (Form)
 * POST /tree/{tree}/orte/gov?place_id=N      → setzt GOV-ID, gibt JSON
 *
 * AJAX-Endpoint für die Ortsliste/Detailseite.
 * Body: gov_id (string) oder unlink=1
 */
class GovLinkPage extends AbstractOrtsregisterHandler
{
    public function __construct(
        private readonly GovApiClient      $client,
        private readonly GovLinkingService $linking,
    ) {}

    protected function respond(
        ServerRequestInterface $request,
        ?Tree                  $tree,
    ): ResponseInterface {
        if ($tree === null) {
            return $this->fragment('<div class="alert alert-warning">Bitte Stammbaum wählen.</div>', 400);
        }
        $params  = $request->getQueryParams();
        $placeId = (int) ($params['place_id'] ?? 0);
        if ($placeId <= 0) {
            return $this->fragment('<div class="alert alert-danger">Ungültige place_id.</div>', 400);
        }

        if (strtoupper($request->getMethod()) === 'POST') {
            return $this->handlePost($request, $tree, $placeId);
        }
        return $this->renderForm($tree, $placeId);
    }

    private function handlePost(ServerRequestInterface $request, Tree $tree, int $placeId): ResponseInterface
    {
        $body   = (array) $request->getParsedBody();
        $unlink = (bool) ($body['unlink'] ?? false);
        $govId  = trim((string) ($body['gov_id'] ?? ''));

        try {
            if ($unlink) {
                $this->linking->unlink($tree, $placeId);
                return $this->json(['success' => true, 'unlinked' => true]);
            }
            if ($govId === '') {
                return $this->json(['success' => false, 'message' => 'Keine GOV-ID angegeben.'], 422);
            }
            $obj = $this->linking->link($tree, $placeId, $govId);
            return $this->json([
                'success'  => true,
                'gov_id'   => $obj->govId,
                'name'     => $obj->primaryName,
                'lat'      => $obj->latitude,
                'lng'      => $obj->longitude,
            ]);
        } catch (Throwable $e) {
            // Status 200 mit success:false — sonst HTML-Fehlerseite → „JSON.parse".
            return $this->json(['success' => false, 'message' => $e->getMessage()], 200);
        }
    }

    private function renderForm(Tree $tree, int $placeId): ResponseInterface
    {
        $existing = $this->linking->getLinkedGovId($tree, $placeId);
        $obj      = $existing !== null ? $this->client->getObject($existing) : null;
        $csrf     = csrf_token();
        $action   = e(route('ortsregister.gov.link', ['tree' => $tree->name(), 'place_id' => $placeId]));

        // Place-Name aus DB holen für vor-befüllte GOV-Suche
        $placeName = $this->loadPlaceName($tree, $placeId);
        $searchUrl = 'https://gov.genealogy.net/search/name?name=' . rawurlencode($placeName);

        $existingBlock = '';
        if ($obj !== null) {
            $coordsLine = $obj->hasCoordinates()
                ? sprintf('%.4f, %.4f', $obj->latitude, $obj->longitude)
                : '—';
            $existingBlock = sprintf(
                '<div class="alert alert-success">'
                . '<strong>Verknüpft:</strong> <code>%s</code><br>'
                . 'Name: <strong>%s</strong><br>'
                . 'Koordinaten: %s<br>'
                . 'Type-IDs: %s'
                . '</div>',
                e($obj->govId),
                e($obj->primaryName),
                e($coordsLine),
                e(implode(', ', $obj->typeIds) ?: '—'),
            );
        }

        $html = sprintf(
            '<div class="modal-header">'
            . '<h5 class="modal-title">GOV-Verknüpfung</h5>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>'
            . '</div>'
            . '<div class="modal-body">'
            . '%s'
            . '<div class="card bg-light mb-3">'
            . '<div class="card-body py-2">'
            . '<div class="d-flex align-items-center gap-2">'
            . '<i class="fas fa-search text-muted"></i>'
            . '<span class="small text-muted">Aktueller Ort:</span>'
            . '<strong>%s</strong>'
            . '<a href="%s" target="_blank" rel="noopener" '
            . '   class="btn btn-sm btn-outline-primary ms-auto">'
            . '<i class="fas fa-link me-1"></i>Auf GOV suchen'
            . '</a>'
            . '</div>'
            . '<div class="form-text mt-1">'
            . 'Klick öffnet GOV-Suche in neuem Tab mit vor-befülltem Namen. '
            . 'Passende ID kopieren und unten einfügen.'
            . '</div>'
            . '</div>'
            . '</div>'
            . '<form id="ortsregister-gov-form" method="POST" action="%s">'
            . '<input type="hidden" name="_csrf" value="%s">'
            . '<div class="mb-3">'
            . '<label for="gov-id-input" class="form-label">GOV-ID</label>'
            . '<input type="text" class="form-control" id="gov-id-input" name="gov_id" '
            . 'placeholder="z.B. HABCHTJN49MC oder object_152487" value="%s" '
            . 'pattern="[A-Za-z0-9_]{3,40}" required>'
            . '<div class="form-text">GOV-ID aus der Trefferliste kopieren. '
            . 'Formate: kompakte Hash-ID (z.B. <code>HABCHTJN49MC</code>) oder '
            . 'Legacy-Form mit Underscore (<code>object_NNN</code>, <code>adm_NNN</code>). '
            . 'Den Objekttyp zeigt GOV nach dem Verknüpfen an, nicht das Prefix.</div>'
            . '</div>'
            . '</form>'
            . '</div>'
            . '<div class="modal-footer">'
            . '%s'
            . '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>'
            . '<button type="submit" form="ortsregister-gov-form" class="btn btn-primary">'
            . '<i class="fas fa-link me-1"></i>Verknüpfen</button>'
            . '</div>',
            $existingBlock,
            e($placeName),
            e($searchUrl),
            $action,
            e($csrf),
            e($existing ?? ''),
            $obj !== null
                ? '<button type="button" class="btn btn-outline-danger me-auto" id="ortsregister-gov-unlink">'
                  . '<i class="fas fa-unlink me-1"></i>Verknüpfung entfernen</button>'
                : '',
        );

        return $this->fragment($html, 200);
    }

    /**
     * Liefert den Place-Namen (unterste Hierarchie-Ebene) für die GOV-Suche.
     */
    private function loadPlaceName(Tree $tree, int $placeId): string
    {
        $row = DB::table('places')
            ->where('p_id',   '=', $placeId)
            ->where('p_file', '=', $tree->id())
            ->select(['p_place'])
            ->first();
        return $row !== null ? (string) $row->p_place : '';
    }

    private function fragment(string $html, int $status): ResponseInterface
    {
        return Registry::responseFactory()->response($html, $status,
            ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return Registry::responseFactory()->response(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
