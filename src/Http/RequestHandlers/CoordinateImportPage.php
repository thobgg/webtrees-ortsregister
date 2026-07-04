<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /tree/{tree}/orte/koordinaten-import
 *
 * Der Koordinaten-Import (Alpha) ist deaktiviert (Issue #1) und zeigt nur noch
 * einen Hinweis. Der frühere Schreibpfad schrieb GEDCOM-Koordinaten
 * (PLAC/MAP/LATI/LONG) in webtrees' baumübergreifenden Orts-Gazetteer
 * (place_location) — konzeptionell falsch, weil Ereignis- und Orts-Koordinaten
 * bewusst getrennt sind. Die Implementierung wurde entfernt; bei einem Rework
 * („Vorschlag pro Ort zur manuellen Übernahme") neu aufsetzen (Git-History).
 */
class CoordinateImportPage extends AbstractOrtsregisterHandler
{
    protected function respond(
        ServerRequestInterface $request,
        ?Tree                  $tree,
    ): ResponseInterface {
        if ($tree === null) {
            return $this->fragment(
                '<div class="alert alert-warning">Bitte Stammbaum wählen.</div>',
                400,
            );
        }

        return $this->fullPage(
            $tree,
            'Koordinaten-Import deaktiviert',
            '<div class="alert alert-info"><i class="fas fa-circle-info me-1"></i> '
            . '<strong>Der Koordinaten-Import ist vorübergehend deaktiviert.</strong></div>'
            . '<p>GEDCOM-Koordinaten (<code>PLAC / MAP / LATI / LONG</code>) beschreiben den Ort '
            . 'eines <em>Ereignisses</em> — nicht den Mittelpunkt des Orts. webtrees pflegt '
            . 'Orts-Koordinaten bewusst getrennt in einem baumübergreifenden Gazetteer. Ein '
            . 'automatisches Befüllen aus Ereignissen kann unpassende Koordinaten setzen.</p>'
            . '<p><a href="' . e(route('ortsregister.orte', ['tree' => $tree->name()])) . '" '
            . 'class="btn btn-primary">Zur Ortsliste</a></p>',
        );
    }

    private function fragment(string $html, int $status): ResponseInterface
    {
        return Registry::responseFactory()->response($html, $status,
            ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function fullPage(Tree $tree, string $title, string $body): ResponseInterface
    {
        return $this->viewResponse($this->viewName('coord-import-page'), [
            'title'  => $title,
            'tree'   => $tree,
            'body'   => $body,
        ]);
    }
}
