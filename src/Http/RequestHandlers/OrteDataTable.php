<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Dto\OrtDto;
use Ortsregister\Repository\OrteRepository;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /tree/{tree}/orte/data
 *
 * Server-side DataTables JSON-Endpoint für die Ortsliste.
 *
 * Spalten:
 *   0  Ort (Name + Pfad, verlinkbar)
 *   1  Ereignisse (Anzahl verknüpfter Datensätze)
 *   2  Koordinaten (ja/nein)
 */
class OrteDataTable extends AbstractDataTableHandler
{
    private const SORT_MAP = [
        0 => 'name',
        1 => 'anzahl',
        2 => 'name',   // Koordinaten nicht sortierbar → Fallback auf Name
    ];

    public function __construct(
        private readonly OrteRepository $orteRepository,
    ) {}

    protected function fetchData(
        ServerRequestInterface $request,
        int    $start,
        int    $length,
        string $search,
        int    $orderColumn,
        string $orderDir,
    ): array {
        try {
            $tree = Validator::attributes($request)->tree();
        } catch (\Throwable) {
            return ['total' => 0, 'filtered' => 0, 'rows' => []];
        }

        $alle     = $this->orteRepository->alleOrte($tree, $search);
        $total    = $this->orteRepository->anzahlOrte($tree);
        $filtered = count($alle);

        // Sortierung
        usort($alle, static function (OrtDto $a, OrtDto $b) use ($orderColumn): int {
            return match ($orderColumn) {
                1       => $a->anzahlEreignisse <=> $b->anzahlEreignisse,
                default => strnatcasecmp($a->vollstaendigerPfad, $b->vollstaendigerPfad),
            };
        });

        if ($orderDir === 'desc') {
            $alle = array_reverse($alle);
        }

        $seite = array_slice($alle, $start, $length);

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => array_map(fn (OrtDto $o) => $this->toRow($o, $tree->name()), $seite),
        ];
    }

    /** @return list<string> */
    private function toRow(OrtDto $ort, string $treeName): array
    {
        // Ort-Spalte: verlinkter Name + ggf. Pfad darunter
        $ortHtml = sprintf(
            '<a href="%s" class="fw-semibold text-decoration-none">%s</a>',
            e(route('ortsregister.orte.detail', ['tree' => $treeName, 'place_id' => $ort->id])),
            e($ort->name)
        );

        if ($ort->vollstaendigerPfad !== $ort->name) {
            $ortHtml .= sprintf(
                '<br><small class="text-muted">%s</small>',
                e($ort->vollstaendigerPfad)
            );
        }

        // Ereignisse-Spalte
        $ereignisseHtml = sprintf(
            '<span class="badge bg-primary rounded-pill">%s</span>',
            I18N::number($ort->anzahlEreignisse)
        );

        // Koordinaten-Spalte
        $koordinatenHtml = ($ort->breitengrad !== null && $ort->laengengrad !== null)
            ? '<i class="fas fa-map-marker-alt text-success" title="' . e(sprintf('%.4f, %.4f', $ort->breitengrad, $ort->laengengrad)) . '"></i>'
            : '<span class="text-muted">&mdash;</span>';

        return [$ortHtml, $ereignisseHtml, $koordinatenHtml];
    }
}
