<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Repository\OrteRepository;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /tree/{tree}/orte
 *
 * Ortsliste mit Filter und Paginierung.
 * Injiziert das OrteRepository über den webtrees-DI-Container.
 */
class OrtePage extends AbstractOrtsregisterHandler
{
    private const PER_PAGE = 50;

    /** User-Preference-Schlüssel für den Hierarchie-Filter-Modus */
    public const PREF_PLACE_FILTER_MODE = 'ortsregister_place_filter_mode';

    public function __construct(
        private readonly OrteRepository $orteRepository
    ) {}

    protected function respond(
        ServerRequestInterface $request,
        ?Tree $tree
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $filter = trim((string) ($params['q'] ?? ''));
        $page   = max(1, (int) ($params['page'] ?? 1));

        $mode = self::readFilterMode();

        if ($tree === null) {
            return $this->viewResponse($this->viewName('orte'), [
                'title'      => I18N::translate('Orte'),
                'tree'       => null,
                'filter'     => $filter,
                'page'       => 1,
                'totalPages' => 1,
                'totalOrte'  => 0,
                'orte'       => [],
                'filterMode' => $mode,
            ]);
        }

        $gesamtAnzahl = $this->orteRepository->anzahlOrte($tree, $filter, $mode);
        $totalPages   = max(1, (int) ceil($gesamtAnzahl / self::PER_PAGE));
        $page         = min($page, $totalPages);

        $alleOrte = $this->orteRepository->alleOrte($tree, $filter, $mode);
        $orte     = array_slice($alleOrte, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return $this->viewResponse($this->viewName('orte'), [
            'title'      => I18N::translate('Orte'),
            'tree'       => $tree,
            'filter'     => $filter,
            'page'       => $page,
            'perPage'    => self::PER_PAGE,
            'totalPages' => $totalPages,
            'totalOrte'  => $gesamtAnzahl,
            'orte'       => $orte,
            'filterMode' => $mode,
            'letzteOps'  => $this->orteRepository->letzteOperationen($tree, 10),
        ]);
    }

    /**
     * Liest die User-Preference. Default: alle Ebenen zeigen (konservativ,
     * niemand wird überrascht). Pref wird via SetPlaceFilterMode geschrieben.
     */
    public static function readFilterMode(): string
    {
        $raw = Auth::user()->getPreference(self::PREF_PLACE_FILTER_MODE);
        return $raw === OrteRepository::MODE_LEAVES
            ? OrteRepository::MODE_LEAVES
            : OrteRepository::MODE_ALL;
    }
}
