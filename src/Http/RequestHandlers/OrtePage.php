<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Repository\OrteRepository;
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

        if ($tree === null) {
            return $this->viewResponse($this->viewName('orte'), [
                'title'      => I18N::translate('Orte'),
                'tree'       => null,
                'filter'     => $filter,
                'page'       => 1,
                'totalPages' => 1,
                'totalOrte'  => 0,
                'orte'       => [],
            ]);
        }

        $gesamtAnzahl = $this->orteRepository->anzahlOrte($tree, $filter);
        $totalPages   = max(1, (int) ceil($gesamtAnzahl / self::PER_PAGE));
        $page         = min($page, $totalPages);

        $alleOrte = $this->orteRepository->alleOrte($tree, $filter);
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
        ]);
    }
}
