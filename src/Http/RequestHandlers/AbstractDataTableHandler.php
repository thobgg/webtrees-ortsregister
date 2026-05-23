<?php
declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Fisharebest\Webtrees\Registry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

abstract class AbstractDataTableHandler implements RequestHandlerInterface
{
    final public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $draw   = (int) ($params['draw'] ?? 1);
        $start  = max(0, (int) ($params['start']  ?? 0));
        $length = max(1, min(200, (int) ($params['length'] ?? 50)));
        $search = trim((string) ($params['search']['value'] ?? $params['search'] ?? ''));
        $orderColumnIndex = (int) ($params['order'][0]['column'] ?? 0);
        $orderDir = strtolower($params['order'][0]['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        ['total' => $total, 'filtered' => $filtered, 'rows' => $rows]
            = $this->fetchData($request, $start, $length, $search, $orderColumnIndex, $orderDir);

        $json = json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return Registry::responseFactory()->response($json, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /** @return array{total: int, filtered: int, rows: list<list<string>>} */
    abstract protected function fetchData(
        ServerRequestInterface $request,
        int    $start,
        int    $length,
        string $search,
        int    $orderColumn,
        string $orderDir,
    ): array;
}
