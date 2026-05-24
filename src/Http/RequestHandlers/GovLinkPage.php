<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Service\GovApiClient;
use Ortsregister\Service\GovLinkingService;
use Fisharebest\Webtrees\Auth;
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
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function renderForm(Tree $tree, int $placeId): ResponseInterface
    {
        $existing = $this->linking->getLinkedGovId($tree, $placeId);
        $obj      = $existing !== null ? $this->client->getObject($existing) : null;
        $csrf     = csrf_token();
        $action   = e(route('ortsregister.gov.link', ['tree' => $tree->name(), 'place_id' => $placeId]));

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
            . '<h5 class="modal-title"><i class="fas fa-globe me-2"></i>GOV-Verknüpfung</h5>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>'
            . '</div>'
            . '<div class="modal-body">'
            . '%s'
            . '<form id="ortsregister-gov-form" method="POST" action="%s">'
            . '<input type="hidden" name="_csrf" value="%s">'
            . '<div class="mb-3">'
            . '<label for="gov-id-input" class="form-label">GOV-ID</label>'
            . '<input type="text" class="form-control" id="gov-id-input" name="gov_id" '
            . 'placeholder="z.B. object_152487" value="%s" pattern="[a-z][a-z_0-9]*_[0-9]+" required>'
            . '<div class="form-text">Format <code>object_NNNNNN</code> oder <code>adm_NNNNNN</code>. '
            . 'GOV-ID findest du auf <a href="https://gov.genealogy.net" target="_blank">gov.genealogy.net</a>.</div>'
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
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
