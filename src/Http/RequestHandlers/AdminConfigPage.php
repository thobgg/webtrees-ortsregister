<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\OrtsregisterModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
    ) {
        // Die Einstellungen gelten baumuebergreifend, also gehoert die Seite
        // ins Verwaltungs-Layout. Vorher lief sie im Baum-Layout und musste
        // sich dafuer irgendeinen Baum greifen - bei mehreren Baeumen stand
        // man dann sichtbar im falschen.
        //
        // Zuweisung im Konstruktor, nicht als Property: ein abweichender
        // Default kollidiert mit ViewResponseTrait::$layout und laesst schon
        // das Laden der Klasse scheitern.
        $this->layout = 'layouts/administration';
    }

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
        return $this->viewResponse(
            OrtsregisterModule::MODULE_NAME . '::admin-config',
            [
                'title'            => I18N::translate('Ortsregister – Einstellungen'),
                'module'           => $this->module,
                'wiki_enabled'     => $this->module->wikiEnabled(),
                'wiki_dist_km'     => $this->module->wikiDistanceKm(),
                'wiki_cache_ttl'   => $this->module->wikiCacheTtl(),
                'gov_cache_ttl'    => $this->module->govCacheTtl(),
                'personen_visible' => $this->module->personenVisible(),
                'medien_visible'   => $this->module->medienVisible(),
                'bilder_visible'   => $this->module->bilderVisible(),
                'markdown_editor'  => $this->module->markdownEditor(),
                'link_wikipedia'   => $this->module->linkWikipedia(),
                'link_matricula'   => $this->module->linkMatricula(),
                'link_archion'     => $this->module->linkArchion(),
                'link_archivpdb'   => $this->module->linkArchivportalD(),
                'link_ddb'         => $this->module->linkDdb(),
                'folder_root'      => $this->module->folderRoot(),
                'hierarchy_mode'   => $this->module->hierarchyMode(),
                'archion_auto_km'  => $this->module->archionAutoDistanceKm(),
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

        // Folder-Root (path-safe: nur a-z0-9_- erlaubt, sonst Default zurueck)
        $folderRoot = trim((string) ($params[OrtsregisterModule::SETTING_FOLDER_ROOT] ?? ''));
        if (preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $folderRoot) !== 1) {
            $folderRoot = OrtsregisterModule::DEFAULT_FOLDER_ROOT;
        }
        $this->module->setPreference(OrtsregisterModule::SETTING_FOLDER_ROOT, $folderRoot);

        // Hierarchy-Modus
        $hierarchyMode = (string) ($params[OrtsregisterModule::SETTING_HIERARCHY_MODE] ?? OrtsregisterModule::DEFAULT_HIERARCHY_MODE);
        if (!in_array($hierarchyMode, [
            OrtsregisterModule::HIERARCHY_MODE_HISTORICAL,
            OrtsregisterModule::HIERARCHY_MODE_CURRENT,
            OrtsregisterModule::HIERARCHY_MODE_BOTH,
        ], true)) {
            $hierarchyMode = OrtsregisterModule::DEFAULT_HIERARCHY_MODE;
        }
        $this->module->setPreference(OrtsregisterModule::SETTING_HIERARCHY_MODE, $hierarchyMode);

        // Archion Auto-Lookup-Radius
        $archionKm = (int) ($params[OrtsregisterModule::SETTING_ARCHION_AUTO_KM] ?? OrtsregisterModule::DEFAULT_ARCHION_AUTO_KM);
        $archionKm = max(1, min(100, $archionKm));
        $this->module->setPreference(OrtsregisterModule::SETTING_ARCHION_AUTO_KM, (string) $archionKm);

        // Checkbox-Toggles (Checkbox: vorhanden = '1', fehlt = '0')
        foreach ([
            OrtsregisterModule::SETTING_MARKDOWN_EDITOR,
            OrtsregisterModule::SETTING_LINK_WIKIPEDIA,
            OrtsregisterModule::SETTING_LINK_MATRICULA,
            OrtsregisterModule::SETTING_LINK_ARCHION,
            OrtsregisterModule::SETTING_LINK_ARCHIVPDB,
            OrtsregisterModule::SETTING_LINK_DDB,
        ] as $key) {
            $this->module->setPreference($key, isset($params[$key]) ? '1' : '0');
        }

        return redirect(route('ortsregister.admin.config'));
    }
}
