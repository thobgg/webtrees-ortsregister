<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Service\CoordinateImportService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /tree/{tree}/orte/koordinaten-import
 *
 * Zeigt eine Übersichtsseite mit analyze-Daten und Confirm-Button.
 * POST auf dieselbe URL führt den Import durch.
 */
class CoordinateImportPage extends AbstractOrtsregisterHandler
{
    public function __construct(
        private readonly CoordinateImportService $service,
    ) {}

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

        // DEAKTIVIERT (Alpha, Issue #1): Der Import schrieb GEDCOM-Koordinaten
        // (PLAC/MAP/LATI/LONG) in webtrees' tree-übergreifend geteilten Orts-
        // Gazetteer (place_location). Diese Koordinaten beschreiben aber den Ort
        // eines EREIGNISSES (z. B. ein Grab), nicht den Ortsmittelpunkt — und
        // webtrees hält Ereignis- und Orts-Koordinaten bewusst getrennt
        // (webtrees-FAQ „locations"). Schreibpfad gesperrt bis zum Rework
        // („Vorschlag pro Ort zur manuellen Übernahme"). Code unten bleibt erhalten.
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

        $method = strtoupper($request->getMethod());
        $autoAccept = Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) === '1';

        if ($method === 'POST') {
            try {
                $result = $this->service->executeImport($tree);
                $html = sprintf(
                    '<div class="alert alert-success"><i class="fas fa-check me-1"></i>'
                    . '<strong>Import erfolgreich.</strong> %d Koordinaten geschrieben, %d übersprungen (bereits vorhanden).</div>'
                    . '<p>Backup: <code>%s</code></p>'
                    . '<p><a href="%s" class="btn btn-primary">Zur Ortsliste</a></p>',
                    $result['written'],
                    $result['skipped'],
                    e(basename($result['backup'])),
                    e(route('ortsregister.orte', ['tree' => $tree->name()])),
                );
                return $this->fullPage($tree, 'Koordinaten-Import erfolgreich', $html);
            } catch (\Throwable $e) {
                $html = '<div class="alert alert-danger"><strong>Fehler:</strong> '
                    . e($e->getMessage()) . '</div>';
                return $this->fullPage($tree, 'Koordinaten-Import fehlgeschlagen', $html, 500);
            }
        }

        // GET — Analyse + Confirm-Formular
        $stats = $this->service->analyzeImport($tree);

        $warn = $autoAccept
            ? ''
            : '<div class="alert alert-warning">Aktiviere zuerst die Einstellung „Änderungen automatisch übernehmen" in deinem Benutzer-Profil.</div>';

        $csrf = csrf_token();
        $action = e(route('ortsregister.coord.import', ['tree' => $tree->name()]));

        $html = sprintf(
            '%s'
            . '<p>Die Operation liest <code>MAP / LATI / LONG</code>-Subtags aus allen PLAC-Strukturen '
            . 'des GEDCOM und schreibt sie in die webtrees-Standardtabelle <code>place_location</code>.</p>'
            . '<table class="table table-bordered w-auto">'
            . '<tr><th class="text-end">Eindeutige PLACs mit Koordinaten im GEDCOM</th><td>%d</td></tr>'
            . '<tr><th class="text-end">Davon bereits vollständig in place_location</th><td>%d</td></tr>'
            . '<tr><th class="text-end">Würden neu angelegt</th><td>%d</td></tr>'
            . '<tr><th class="text-end">Würden aktualisiert (place_location existiert, Koords leer)</th><td>%d</td></tr>'
            . '</table>'
            . '<p class="text-muted small">Vor dem Schreiben wird ein Backup als JSON unter '
            . '<code>modules_v4/ortsregister/backups/</code> angelegt.</p>'
            . '<form method="POST" action="%s">'
            . '<input type="hidden" name="_csrf" value="%s">'
            . '<button type="submit" class="btn btn-primary" %s>'
            . '<i class="fas fa-download me-1"></i>Import starten</button>'
            . ' <a href="%s" class="btn btn-secondary">Abbrechen</a>'
            . '</form>',
            $warn,
            $stats['unique_placs_with_coords'],
            $stats['already_in_place_location'],
            $stats['would_insert'],
            $stats['would_update'],
            $action,
            e($csrf),
            $autoAccept ? '' : 'disabled',
            e(route('ortsregister.orte', ['tree' => $tree->name()])),
        );

        return $this->fullPage($tree, 'Koordinaten aus PLAC-Subtags importieren', $html);
    }

    private function fragment(string $html, int $status): ResponseInterface
    {
        return Registry::responseFactory()->response($html, $status,
            ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function fullPage(Tree $tree, string $title, string $body, int $status = 200): ResponseInterface
    {
        return $this->viewResponse($this->viewName('coord-import-page'), [
            'title'  => $title,
            'tree'   => $tree,
            'body'   => $body,
        ]);
    }
}
