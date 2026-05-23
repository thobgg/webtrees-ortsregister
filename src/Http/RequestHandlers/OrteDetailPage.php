<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Repository\OrteRepository;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /tree/{tree}/orte/{place_id}
 *
 * Detailseite für einen einzelnen Ort.
 */
class OrteDetailPage extends AbstractOrtsregisterHandler
{
    public function __construct(
        private readonly OrteRepository $orteRepository
    ) {}

    protected function respond(
        ServerRequestInterface $request,
        ?Tree $tree
    ): ResponseInterface {
        if ($tree === null) {
            return $this->viewResponse($this->viewName('ort-detail'), [
                'title' => I18N::translate('Ort'),
                'tree'  => null,
                'ort'   => null,
                'personen' => [],
                'medien'   => [],
            ]);
        }

        $placeId = (int) ($request->getAttribute('place_id') ?? 0);

        $ort = $this->orteRepository->findeOrtById($tree, $placeId);

        if ($ort === null) {
            return $this->viewResponse($this->viewName('ort-detail'), [
                'title'    => I18N::translate('Ort nicht gefunden'),
                'tree'     => $tree,
                'ort'      => null,
                'personen' => [],
                'medien'   => [],
            ]);
        }

        // Personen mit Ereignissen an diesem Ort
        $personen = $this->ladePersonen($tree, $placeId);

        // Medien die an diesem Ort hängen
        $medien = $this->ladeMedien($tree, $placeId);

        return $this->viewResponse($this->viewName('ort-detail'), [
            'title'    => $ort->name,
            'tree'     => $tree,
            'ort'      => $ort,
            'personen' => $personen,
            'medien'   => $medien,
        ]);
    }

    /**
     * Lädt alle Personen mit Ereignissen an diesem Ort.
     */
    private function ladePersonen(Tree $tree, int $placeId): array
    {
        $rows = DB::table('placelinks AS pl')
            ->join('individuals AS i', function ($join) use ($tree) {
                $join->on('i.i_id', '=', 'pl.pl_gid')
                     ->where('i.i_file', '=', $tree->id());
            })
            ->where('pl.pl_p_id', '=', $placeId)
            ->where('pl.pl_file', '=', $tree->id())
            ->select(['i.i_id', 'i.i_gedcom'])
            ->distinct()
            ->orderBy('i.i_id')
            ->limit(200)
            ->get();

        $personen = [];
        foreach ($rows as $row) {
            $individual = Registry::individualFactory()->make($row->i_id, $tree);
            if ($individual !== null && $individual->canShow()) {
                $personen[] = $individual;
            }
        }

        return $personen;
    }

    /**
     * Lädt alle Medien, die indirekt an diesem Ort hängen.
     *
     * webtrees hat keine direkte Ort→Medium-Verknüpfung. Medien hängen an
     * Individuen/Familien (link.l_type = 'OBJE'), die ihrerseits über
     * placelinks mit dem Ort verbunden sind.
     *
     * @return list<\Fisharebest\Webtrees\Media>
     */
    private function ladeMedien(Tree $tree, int $placeId): array
    {
        $rows = DB::table('media AS m')
            ->join('link AS l', function ($join) use ($tree) {
                $join->on('l.l_to', '=', 'm.m_id')
                     ->on('l.l_file', '=', 'm.m_file')
                     ->where('l.l_type', '=', 'OBJE');
            })
            ->join('placelinks AS pl', function ($join) use ($tree) {
                $join->on('pl.pl_gid', '=', 'l.l_from')
                     ->on('pl.pl_file', '=', 'm.m_file');
            })
            ->where('m.m_file', '=', $tree->id())
            ->where('pl.pl_p_id', '=', $placeId)
            ->select(['m.m_id', 'm.m_gedcom'])
            ->distinct()
            ->orderBy('m.m_id')
            ->limit(100)
            ->get();

        $medien = [];
        foreach ($rows as $row) {
            $medium = Registry::mediaFactory()->make($row->m_id, $tree);
            if ($medium !== null && $medium->canShow()) {
                $medien[] = $medium;
            }
        }

        return $medien;
    }
}
