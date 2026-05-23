<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Repository\OrteRepository;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /tree/{tree}/orte/karte
 *
 * Leaflet-Kartenansicht aller Orte mit Geokoordinaten.
 * Orte ohne Koordinaten werden in der Karte nicht angezeigt.
 */
class OrteKarte extends AbstractOrtsregisterHandler
{
    public function __construct(
        private readonly OrteRepository $orteRepository
    ) {}

    protected function respond(
        ServerRequestInterface $request,
        ?Tree $tree
    ): ResponseInterface {
        if ($tree === null) {
            return $this->viewResponse($this->viewName('orte-karte'), [
                'title'      => I18N::translate('Ortskarte'),
                'tree'       => null,
                'geoJson'    => '{"type":"FeatureCollection","features":[]}',
                'anzahlMitKoordinaten' => 0,
                'anzahlOhneKoordinaten' => 0,
            ]);
        }

        $alleOrte = $this->orteRepository->alleOrte($tree);

        $mitKoordinaten    = array_filter($alleOrte, fn ($o) => $o->hatKoordinaten());
        $ohneKoordinaten   = array_filter($alleOrte, fn ($o) => !$o->hatKoordinaten());

        // GeoJSON für Leaflet aufbauen
        $features = [];
        foreach ($mitKoordinaten as $ort) {
            $features[] = [
                'type'       => 'Feature',
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [$ort->laengengrad, $ort->breitengrad], // GeoJSON: [lng, lat]
                ],
                'properties' => [
                    'id'         => $ort->id,
                    'name'       => $ort->anzeigeName(),
                    'ereignisse' => $ort->anzahlEreignisse,
                    'popup'      => sprintf(
                        '<strong>%s</strong><br><small>%s: %s</small>',
                        htmlspecialchars($ort->anzeigeName(), ENT_QUOTES),
                        I18N::translate('Ereignisse'),
                        I18N::number($ort->anzahlEreignisse)
                    ),
                ],
            ];
        }

        $geoJson = json_encode(
            ['type' => 'FeatureCollection', 'features' => $features],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );

        return $this->viewResponse($this->viewName('orte-karte'), [
            'title'                => I18N::translate('Ortskarte'),
            'tree'                 => $tree,
            'geoJson'              => $geoJson,
            'anzahlMitKoordinaten' => count($mitKoordinaten),
            'anzahlOhneKoordinaten' => count($ohneKoordinaten),
        ]);
    }
}
