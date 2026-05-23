<?php

declare(strict_types=1);

namespace Ortsregister;

use Ortsregister\Http\RequestHandlers\OrteDataTable;
use Ortsregister\Http\RequestHandlers\OrteDetailPage;
use Ortsregister\Http\RequestHandlers\OrteKarte;
use Ortsregister\Http\RequestHandlers\OrtePage;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use Illuminate\Database\Schema\Blueprint;

/**
 * Ortsregister – Visuelle Landing-Page pro Ort mit Hauptfoto, Medien,
 * Personen-Ereignissen und (geplant) GOV-Integration über Vesta.
 *
 * Hinweis: Die heutigen Orte-Handler arbeiten noch auf der webtrees-eigenen
 * `places`-Tabelle. Eigene Tabellen (`ortsregister_ort*`) kommen mit
 * Stufe 1 der Orte-Roadmap.
 */
class OrtsregisterModule extends AbstractModule implements
    ModuleCustomInterface,
    ModuleMenuInterface,
    ModuleGlobalInterface
{
    use ModuleCustomTrait;
    use ModuleMenuTrait;
    use ModuleGlobalTrait;

    public const MODULE_NAME = '_ortsregister_';

    public function title(): string { return 'Ortsregister'; }
    public function description(): string { return 'Ortsregister mit visueller Landing-Page, Medien-Verknüpfung und (geplant) GOV-Integration.'; }
    public function customModuleAuthorName(): string { return 'Thomas Bugge'; }
    public function customModuleVersion(): string { return '0.1.0'; }
    public function customModuleLatestVersion(): string { return '0.1.0'; }
    public function customModuleSupportUrl(): string { return ''; }

    public function boot(): void
    {
        $this->migrateDatabase();
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        $router = Registry::routeFactory()->routeMap();

        $router->get('ortsregister.orte',        '/tree/{tree}/orte',                    OrtePage::class);
        $router->get('ortsregister.orte.data',   '/tree/{tree}/orte/data',               OrteDataTable::class);
        $router->get('ortsregister.orte.karte',  '/tree/{tree}/orte/karte',              OrteKarte::class);
        $router->get('ortsregister.orte.detail', '/tree/{tree}/orte/{place_id}',         OrteDetailPage::class);
    }

    public function defaultMenuOrder(): int { return 99; }

    public function getMenu(Tree $tree): ?Menu
    {
        if (!Auth::isMember($tree)) {
            return null;
        }

        return new Menu(
            I18N::translate('Ortsregister'),
            route('ortsregister.orte', ['tree' => $tree->name()]),
            'menu-ortsregister',
            ['rel' => 'nofollow'],
        );
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    public function headContent(): string
    {
        $path = $this->resourcesFolder() . 'menu-icon.png';
        if (!file_exists($path) || !class_exists('Imagick')) {
            return '';
        }
        try {
            $im = new \Imagick($path);

            // Hintergrund-Pixel (oben-links) bestimmen und alle gleichen
            // Pixel transparent setzen – fuzz ~10 % toleriert leichte Abweichungen
            $bg = $im->getImagePixelColor(0, 0)->getColorAsString();
            $im->setImageMatte(true);
            $im->transparentPaintImage($bg, 0.0, (int) (0.10 * \Imagick::getQuantum()), false);

            $im->thumbnailImage(50, 50, true, true);
            $im->setImageFormat('png');
            $b64 = base64_encode($im->getImageBlob());
            $im->destroy();
        } catch (\Throwable) {
            return '';
        }
        return '<style>'
            . '.menu-ortsregister .nav-link:before{'
            . 'content:url("data:image/png;base64,' . $b64 . '")}'
            . '</style>';
    }

    /**
     * Datenbank-Migrationen. Aktuell nichts – Tabellen `ortsregister_ort*`
     * kommen mit Stufe 1 der Orte-Roadmap.
     */
    private function migrateDatabase(): void
    {
        // Schema-Migrationen folgen mit Stufe 1.
    }
}
