<?php

declare(strict_types=1);

namespace Ortsregister;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Http\RequestHandlers\MergeExecute;
use Ortsregister\Http\RequestHandlers\MergeModalPage;
use Ortsregister\Http\RequestHandlers\OrteDataTable;
use Ortsregister\Http\RequestHandlers\OrteDetailPage;
use Ortsregister\Http\RequestHandlers\OrteKarte;
use Ortsregister\Http\RequestHandlers\OrtePage;
use Ortsregister\Service\GedcomPlaceManipulator;
use Ortsregister\Service\PlaceOperationService;
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
        $this->registerServices();
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        $router = Registry::routeFactory()->routeMap();

        $router->get ('ortsregister.orte',          '/tree/{tree}/orte',                OrtePage::class);
        $router->get ('ortsregister.orte.data',     '/tree/{tree}/orte/data',           OrteDataTable::class);
        $router->get ('ortsregister.orte.karte',    '/tree/{tree}/orte/karte',          OrteKarte::class);
        $router->get ('ortsregister.merge.preview', '/tree/{tree}/orte/merge/preview',  MergeModalPage::class);
        $router->post('ortsregister.merge.execute', '/tree/{tree}/orte/merge/execute',  MergeExecute::class);
        $router->get ('ortsregister.orte.detail',   '/tree/{tree}/orte/{place_id}',     OrteDetailPage::class);
    }

    /**
     * Bindet Modul-Services in den webtrees-Container.
     * Notwendig für PlaceOperationService, da dessen Konstruktor einen
     * String-Parameter (backupDir) hat, den der Auto-Wirer nicht auflösen kann.
     */
    private function registerServices(): void
    {
        $container = Registry::container();
        $container->set(
            PlaceOperationService::class,
            new PlaceOperationService(
                $container->get(ApcuCacheService::class),
                $container->get(GedcomPlaceManipulator::class),
                __DIR__ . '/../backups',
            ),
        );
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

    private const SCHEMA_VERSION = 1;

    private function migrateDatabase(): void
    {
        $schema = DB::schema();
        $didDdl = false;

        // Idempotente Migration über hasTable() — kein SCHEMA_VERSION-Gate,
        // weil ein vorheriger fehlgeschlagener Boot die Tabellen-Erstellung
        // übersprungen haben kann, während ein anderer Code-Pfad bereits
        // SCHEMA_VERSION gesetzt hat.
        if (!$schema->hasTable('ortsregister_place_meta')) {
            $schema->create('ortsregister_place_meta', function (Blueprint $table): void {
                $table->integer('place_id');
                $table->integer('tree_id');
                $table->text('meta_data');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->primary(['place_id', 'tree_id']);
                $table->index('tree_id');
            });
            $didDdl = true;
        }

        if (!$schema->hasTable('ortsregister_merge_log')) {
            $schema->create('ortsregister_merge_log', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->integer('tree_id');
                $table->string('operation', 32);
                $table->integer('src_place_id')->nullable();
                $table->integer('dst_place_id')->nullable();
                $table->integer('user_id')->nullable();
                $table->string('backup_path', 255);
                $table->string('status', 16)->default('completed');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['tree_id', 'created_at']);
            });
            $didDdl = true;
        }

        // CREATE TABLE ist DDL und commit-implizit – das beendet die von
        // webtrees' UseTransaction-Middleware aussen gestartete Transaktion.
        // Wir starten eine neue, damit das spätere Commit der Middleware
        // nicht mit "no active transaction" abbricht. (Pattern aus Sammlungen-Modul.)
        if ($didDdl) {
            try {
                $pdo = DB::connection()->getPdo();
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                }
            } catch (\Throwable) {
                // Best effort.
            }
        }

        $this->setPreference('SCHEMA_VERSION', (string) self::SCHEMA_VERSION);
    }
}
