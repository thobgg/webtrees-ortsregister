<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\OrtsregisterModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * GET/POST /admin/module/ortsregister
 *
 * Admin-Konfiguration: Wikimedia-Lookup, Cache-TTLs, Listen-Längen.
 */
class AdminConfigPage implements RequestHandlerInterface
{
    use ViewResponseTrait;

    public function __construct(
        private readonly OrtsregisterModule $module,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            throw new HttpAccessDeniedException(
                I18N::translate('Sie haben keine Berechtigung für diese Seite.')
            );
        }
        if ($request->getMethod() === 'POST') {
            return $this->save($request);
        }
        return $this->showForm();
    }

    private function showForm(): ResponseInterface
    {
        // Layout default.phtml verlangt $tree im Scope (für Menü etc.).
        // Wir nehmen den ersten verfügbaren Baum; im Admin-Kontext ist das
        // belanglos, der Baum wird hier nur für Menü-Rendering gebraucht.
        $tree = null;
        try {
            $treeService = Registry::container()->get(TreeService::class);
            $tree        = $treeService->all()->first();
        } catch (Throwable) {
            // ohne Baum geht's auch — Layout muss es vertragen
        }

        return $this->viewResponse(
            OrtsregisterModule::MODULE_NAME . '::admin-config',
            [
                'title'            => I18N::translate('Ortsregister – Einstellungen'),
                'tree'             => $tree,
                'module'           => $this->module,
                'wiki_enabled'     => $this->module->wikiEnabled(),
                'wiki_dist_km'     => $this->module->wikiDistanceKm(),
                'wiki_cache_ttl'   => $this->module->wikiCacheTtl(),
                'gov_cache_ttl'    => $this->module->govCacheTtl(),
                'personen_visible' => $this->module->personenVisible(),
                'medien_visible'   => $this->module->medienVisible(),
                'bilder_visible'   => $this->module->bilderVisible(),
            ]
        );
    }

    private function save(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getParsedBody();

        $wikiEnabled = isset($params[OrtsregisterModule::SETTING_WIKI_ENABLED]) ? '1' : '0';
        $distKm      = max(1,  min(2000,    (int) ($params[OrtsregisterModule::SETTING_WIKI_DIST_KM]     ?? 30)));
        $wikiTtl     = max(60, min(2592000, (int) ($params[OrtsregisterModule::SETTING_WIKI_CACHE_TTL]   ?? 604800)));
        $govTtl      = max(60, min(2592000, (int) ($params[OrtsregisterModule::SETTING_GOV_CACHE_TTL]    ?? 604800)));
        $personenVis = max(1,  min(200,     (int) ($params[OrtsregisterModule::SETTING_PERSONEN_VISIBLE] ?? 10)));
        $medienVis   = max(1,  min(200,     (int) ($params[OrtsregisterModule::SETTING_MEDIEN_VISIBLE]   ?? 5)));
        $bilderVis   = max(1,  min(200,     (int) ($params[OrtsregisterModule::SETTING_BILDER_VISIBLE]   ?? 12)));

        $this->module->setPreference(OrtsregisterModule::SETTING_WIKI_ENABLED,     $wikiEnabled);
        $this->module->setPreference(OrtsregisterModule::SETTING_WIKI_DIST_KM,     (string) $distKm);
        $this->module->setPreference(OrtsregisterModule::SETTING_WIKI_CACHE_TTL,   (string) $wikiTtl);
        $this->module->setPreference(OrtsregisterModule::SETTING_GOV_CACHE_TTL,    (string) $govTtl);
        $this->module->setPreference(OrtsregisterModule::SETTING_PERSONEN_VISIBLE, (string) $personenVis);
        $this->module->setPreference(OrtsregisterModule::SETTING_MEDIEN_VISIBLE,   (string) $medienVis);
        $this->module->setPreference(OrtsregisterModule::SETTING_BILDER_VISIBLE,   (string) $bilderVis);

        return redirect(route('ortsregister.admin.config'));
    }
}
