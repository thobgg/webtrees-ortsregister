<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Dto\OrtDto;
use Ortsregister\Repository\OrteRepository;
use Ortsregister\Service\VariantenGruppierung;
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
        0 => 'name',   // Auswahl-Spalte nicht sortierbar → Fallback Name
        1 => 'name',
        2 => 'anzahl',
        3 => 'name',   // Koordinaten nicht sortierbar → Fallback Name
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

        $mode = OrtePage::readFilterMode();

        $alle     = $this->orteRepository->alleOrte($tree, $search, $mode);
        $total    = $this->orteRepository->anzahlOrte($tree, '', $mode);
        $filtered = count($alle);

        // Varianten-Gruppen (Issue #11): über die UNGEFILTERTE Liste zählen (Cache-Hit),
        // sonst zerreißt ein aktiver Suchfilter die Gruppen und die Zahlen stimmen nicht.
        $gruppen = VariantenGruppierung::zaehle(
            $search === '' ? $alle : $this->orteRepository->alleOrte($tree, '', $mode),
        );

        // Sortierung — Spalte 2 = Ereignisse (numerisch), sonst Pfad (natürlich)
        usort($alle, static function (OrtDto $a, OrtDto $b) use ($orderColumn): int {
            return match ($orderColumn) {
                2       => $a->anzahlEreignisse <=> $b->anzahlEreignisse,
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
            'rows'     => array_map(fn (OrtDto $o) => $this->toRow($o, $tree->name(), $gruppen), $seite),
        ];
    }

    /**
     * @param array{name: array<string,int>, gov: array<string,int>} $gruppen
     * @return list<string>
     */
    private function toRow(OrtDto $ort, string $treeName, array $gruppen): array
    {
        // Auswahl-Spalte: Q/Z-Radios für Merge + GOV-Verknüpfen-Button
        $auswahlHtml = sprintf(
            '<div class="d-flex flex-wrap gap-1 align-items-center ortsregister-select" data-place-id="%1$d">'
            . '<input type="radio" class="btn-check ortsregister-src" name="ortsregister-src" '
            .   'id="src-%1$d" value="%1$d" autocomplete="off">'
            . '<label class="btn btn-sm btn-outline-warning" for="src-%1$d" title="Als Quelle">Q</label>'
            . '<input type="radio" class="btn-check ortsregister-dst" name="ortsregister-dst" '
            .   'id="dst-%1$d" value="%1$d" autocomplete="off">'
            . '<label class="btn btn-sm btn-outline-success" for="dst-%1$d" title="Als Ziel">Z</label>'
            . '<button type="button" class="btn btn-sm btn-outline-info ms-1 ortsregister-gov-btn" '
            .   'data-place-id="%1$d" title="GOV-Verknüpfung">GOV</button>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary ortsregister-rename-btn" '
            .   'data-place-id="%1$d" title="Umbenennen">Name</button>'
            . '</div>',
            $ort->id,
        );

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

        // Varianten-Gruppen-Signale (Issue #11): webtrees legt pro Schreibweise der
        // Elternkette einen eigenen Orts-Datensatz an — hier sichtbar machen, was
        // wahrscheinlich EIN realer Ort ist. Das Modul zeigt, der Nutzer entscheidet
        // auf der Ortsseite (Merge für Rauschen, GOV-Verknüpfen für Zeit-Varianten).
        $govCount  = $ort->hatGov() ? ($gruppen['gov'][(string) $ort->govId] ?? 0) : 0;
        $nameCount = $gruppen['name'][VariantenGruppierung::nameKey($ort->name)] ?? 0;
        if ($govCount > 1) {
            // Autoritativ: gleiche GOV-Kennung = sicher derselbe reale Ort.
            $ortHtml .= sprintf(
                ' <span class="badge text-bg-info" title="%s">%s</span>',
                e(I18N::translate('Über dieselbe GOV-Kennung als EIN realer Ort verknüpft — Querverweise auf der Ortsseite')),
                e(sprintf('%d× %s', $govCount, I18N::translate('derselbe Ort'))),
            );
        } elseif ($nameCount > 1) {
            // Heuristik: gleicher Name = Kandidaten (Schreibvariante, Zeit-Variante oder Namensvetter).
            $ortHtml .= sprintf(
                ' <span class="badge text-bg-warning" title="%s">%s</span>',
                e(I18N::translate('Mehrere Orts-Einträge mit diesem Namen — auf der Ortsseite prüfen: Schreibvarianten zusammenführen oder Zeit-Varianten über GOV verknüpfen')),
                e(sprintf('%d× %s', $nameCount, I18N::translate('gleicher Name'))),
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

        // GOV-Status-Spalte: grüner Haken wenn verknüpft, sonst —
        $govHtml = $ort->hatGov()
            ? '<i class="fas fa-check text-success" title="' . e('GOV: ' . (string) $ort->govId) . '"></i>'
            : '<span class="text-muted">&mdash;</span>';

        return [$auswahlHtml, $ortHtml, $ereignisseHtml, $koordinatenHtml, $govHtml];
    }
}
