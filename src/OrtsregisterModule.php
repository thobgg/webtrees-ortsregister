<?php

declare(strict_types=1);

namespace Ortsregister;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Http\RequestHandlers\AdminConfigPage;
use Ortsregister\Http\RequestHandlers\CoordinateImportPage;
use Ortsregister\Http\RequestHandlers\PlaceFileServe;
use Ortsregister\Http\RequestHandlers\PlaceNotesSave;
use Ortsregister\Http\RequestHandlers\PlaceNotesToggleTask;
use Ortsregister\Http\RequestHandlers\GovLinkPage;
use Ortsregister\Http\RequestHandlers\MergeExecute;
use Ortsregister\Http\RequestHandlers\MergeModalPage;
use Ortsregister\Http\RequestHandlers\OrteDataTable;
use Ortsregister\Http\RequestHandlers\OrteDetailPage;
use Ortsregister\Http\RequestHandlers\OrteKarte;
use Ortsregister\Http\RequestHandlers\OrtePage;
use Ortsregister\Http\RequestHandlers\SetPlaceFilterMode;
use Ortsregister\Service\CoordinateImportService;
use Ortsregister\Service\DdbClient;
use Ortsregister\Service\GedcomCoordinateExtractor;
use Ortsregister\Service\GedcomPlaceManipulator;
use Ortsregister\Service\GovApiClient;
use Ortsregister\Service\GovHierarchyResolver;
use Ortsregister\Service\GovLinkingService;
use Ortsregister\Service\PlaceEventCounter;
use Ortsregister\Service\ArchionLinker;
use Ortsregister\Service\PlaceFolderScanner;
use Ortsregister\Service\PlaceNotesService;
use Ortsregister\Service\PlaceOperationService;
use Ortsregister\Service\WikimediaPlaceClient;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
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
    ModuleGlobalInterface,
    ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleMenuTrait;
    use ModuleGlobalTrait;
    use ModuleConfigTrait;

    public const MODULE_NAME = '_ortsregister_';

    // ----- Settings-Konstanten + Defaults --------------------------------
    public const SETTING_WIKI_ENABLED     = 'wiki_enabled';
    public const SETTING_WIKI_DIST_KM     = 'wiki_dist_km';
    public const SETTING_WIKI_CACHE_TTL   = 'wiki_cache_ttl';
    public const SETTING_GOV_CACHE_TTL    = 'gov_cache_ttl';
    public const SETTING_PERSONEN_VISIBLE = 'personen_visible';
    public const SETTING_MEDIEN_VISIBLE   = 'medien_visible';
    public const SETTING_BILDER_VISIBLE   = 'bilder_visible';
    public const SETTING_DDB_API_KEY      = 'ddb_api_key';
    public const SETTING_LINK_WIKIPEDIA   = 'link_wikipedia';
    public const SETTING_LINK_MATRICULA   = 'link_matricula';
    public const SETTING_LINK_ARCHION     = 'link_archion';
    public const SETTING_LINK_ARCHIVPDB   = 'link_archivpdb';
    public const SETTING_LINK_DDB         = 'link_ddb';
    public const SETTING_FOLDER_ROOT      = 'folder_root';

    public const DEFAULT_WIKI_ENABLED     = true;
    public const DEFAULT_WIKI_DIST_KM     = 30;
    public const DEFAULT_WIKI_CACHE_TTL   = 604800; // 7 Tage
    public const DEFAULT_GOV_CACHE_TTL    = 604800;
    public const DEFAULT_PERSONEN_VISIBLE = 10;
    public const DEFAULT_MEDIEN_VISIBLE   = 5;
    public const DEFAULT_BILDER_VISIBLE   = 12;
    public const DEFAULT_LINK_WIKIPEDIA   = true;
    public const DEFAULT_LINK_MATRICULA   = true;
    public const DEFAULT_LINK_ARCHION     = true;
    public const DEFAULT_LINK_ARCHIVPDB   = true;
    public const DEFAULT_LINK_DDB         = true;
    public const DEFAULT_FOLDER_ROOT      = 'orte';

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
        View::registerNamespace(self::MODULE_NAME, $this->resourcesFolder() . 'views/');

        $router = Registry::routeFactory()->routeMap();

        $router->get('ortsregister.orte',          '/tree/{tree}/orte',                OrtePage::class);
        $router->get('ortsregister.orte.data',     '/tree/{tree}/orte/data',           OrteDataTable::class);
        $router->get('ortsregister.orte.karte',    '/tree/{tree}/orte/karte',          OrteKarte::class);
        $router->get('ortsregister.merge.preview', '/tree/{tree}/orte/merge/preview',  MergeModalPage::class);
        $router->get('ortsregister.merge.execute', '/tree/{tree}/orte/merge/execute',  MergeExecute::class)
               ->allows('POST');
        $router->get('ortsregister.filter-mode',   '/tree/{tree}/orte/filter-mode',    SetPlaceFilterMode::class)
               ->allows('POST');
        $router->get('ortsregister.coord.import',  '/tree/{tree}/orte/koordinaten-import', CoordinateImportPage::class)
               ->allows('POST');
        $router->get('ortsregister.gov.link',      '/tree/{tree}/orte/gov',            GovLinkPage::class)
               ->allows('POST');
        // WICHTIG: spezifische Routes MUESSEN vor der {place_id}-Catch-all-Route registriert werden,
        // sonst matcht z.B. 'datei' als place_id -> "Ort nicht gefunden".
        $router->get('ortsregister.file',          '/tree/{tree}/orte/datei',          PlaceFileServe::class);
        $router->post('ortsregister.notes.save',         '/tree/{tree}/orte/{place_id}/notizen',        PlaceNotesSave::class);
        $router->post('ortsregister.notes.toggle-task',  '/tree/{tree}/orte/{place_id}/notizen/toggle', PlaceNotesToggleTask::class);
        $router->get('ortsregister.orte.detail',   '/tree/{tree}/orte/{place_id}',     OrteDetailPage::class);
        $router->get('ortsregister.admin.config',  '/ortsregister/admin/config',       AdminConfigPage::class)
               ->allows('POST');
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
        $container->set(
            CoordinateImportService::class,
            new CoordinateImportService(
                $container->get(ApcuCacheService::class),
                $container->get(GedcomCoordinateExtractor::class),
                __DIR__ . '/../backups',
            ),
        );
        // GOV-Stack: API-Client + Linking-Service (Autowiring würde reichen,
        // wir setzen explizit für Sichtbarkeit + um GovApiClient als Singleton
        // mit demselben Cache zu pinnen).
        $container->set(
            GovApiClient::class,
            new GovApiClient($container->get(ApcuCacheService::class), $this->govCacheTtl()),
        );
        $container->set(
            GovLinkingService::class,
            new GovLinkingService($container->get(GovApiClient::class)),
        );
        $container->set(
            GovHierarchyResolver::class,
            new GovHierarchyResolver($container->get(GovApiClient::class)),
        );
        $container->set(
            PlaceEventCounter::class,
            new PlaceEventCounter(),
        );
        $container->set(
            WikimediaPlaceClient::class,
            new WikimediaPlaceClient(
                $container->get(ApcuCacheService::class),
                $this->wikiDistanceKm(),
                $this->wikiCacheTtl(),
                $this->wikiEnabled(),
            ),
        );
        $container->set(
            DdbClient::class,
            new DdbClient(
                $container->get(ApcuCacheService::class),
                $this->ddbApiKey(),
                $this->govCacheTtl(),
            ),
        );
        $container->set(
            PlaceFolderScanner::class,
            new PlaceFolderScanner($this->folderRoot()),
        );
        $container->set(
            PlaceNotesService::class,
            new PlaceNotesService($this->folderRoot()),
        );
        $container->set(
            ArchionLinker::class,
            new ArchionLinker($this->folderRoot()),
        );
        // AdminConfigPage: braucht das Modul selbst
        $container->set(
            AdminConfigPage::class,
            new AdminConfigPage($this),
        );
        // OrteDetailPage: braucht ebenfalls das Modul (für Listen-Längen)
        $container->set(
            OrteDetailPage::class,
            new OrteDetailPage(
                $container->get(\Ortsregister\Repository\OrteRepository::class),
                $container->get(GovLinkingService::class),
                $container->get(GovHierarchyResolver::class),
                $container->get(PlaceEventCounter::class),
                $container->get(WikimediaPlaceClient::class),
                $container->get(DdbClient::class),
                $container->get(PlaceFolderScanner::class),
                $container->get(PlaceNotesService::class),
                $container->get(ArchionLinker::class),
                $this,
            ),
        );
    }

    public function defaultMenuOrder(): int { return 99; }

    // ----- ModuleConfigInterface ----------------------------------------
    public function getConfigLink(): string
    {
        return route('ortsregister.admin.config');
    }

    // ----- Typed Pref-Helpers (Defaults + Klammern) ---------------------
    public function wikiEnabled(): bool
    {
        return $this->getPreference(self::SETTING_WIKI_ENABLED, self::DEFAULT_WIKI_ENABLED ? '1' : '0') === '1';
    }
    public function wikiDistanceKm(): int
    {
        return max(1, min(2000, (int) $this->getPreference(self::SETTING_WIKI_DIST_KM, (string) self::DEFAULT_WIKI_DIST_KM)));
    }
    public function wikiCacheTtl(): int
    {
        return max(60, (int) $this->getPreference(self::SETTING_WIKI_CACHE_TTL, (string) self::DEFAULT_WIKI_CACHE_TTL));
    }
    public function govCacheTtl(): int
    {
        return max(60, (int) $this->getPreference(self::SETTING_GOV_CACHE_TTL, (string) self::DEFAULT_GOV_CACHE_TTL));
    }
    public function personenVisible(): int
    {
        return max(1, min(200, (int) $this->getPreference(self::SETTING_PERSONEN_VISIBLE, (string) self::DEFAULT_PERSONEN_VISIBLE)));
    }
    public function medienVisible(): int
    {
        return max(1, min(200, (int) $this->getPreference(self::SETTING_MEDIEN_VISIBLE, (string) self::DEFAULT_MEDIEN_VISIBLE)));
    }
    public function bilderVisible(): int
    {
        return max(1, min(200, (int) $this->getPreference(self::SETTING_BILDER_VISIBLE, (string) self::DEFAULT_BILDER_VISIBLE)));
    }
    public function ddbApiKey(): string
    {
        return trim($this->getPreference(self::SETTING_DDB_API_KEY, ''));
    }
    public function linkWikipedia(): bool
    {
        return $this->getPreference(self::SETTING_LINK_WIKIPEDIA, self::DEFAULT_LINK_WIKIPEDIA ? '1' : '0') === '1';
    }
    public function linkMatricula(): bool
    {
        return $this->getPreference(self::SETTING_LINK_MATRICULA, self::DEFAULT_LINK_MATRICULA ? '1' : '0') === '1';
    }
    public function linkArchion(): bool
    {
        return $this->getPreference(self::SETTING_LINK_ARCHION, self::DEFAULT_LINK_ARCHION ? '1' : '0') === '1';
    }
    public function linkArchivportalD(): bool
    {
        return $this->getPreference(self::SETTING_LINK_ARCHIVPDB, self::DEFAULT_LINK_ARCHIVPDB ? '1' : '0') === '1';
    }
    public function linkDdb(): bool
    {
        return $this->getPreference(self::SETTING_LINK_DDB, self::DEFAULT_LINK_DDB ? '1' : '0') === '1';
    }
    public function folderRoot(): string
    {
        $raw = trim($this->getPreference(self::SETTING_FOLDER_ROOT, self::DEFAULT_FOLDER_ROOT));
        // Path-traversal-defensiv: nur a-z0-9_- erlauben, sonst Default
        return preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $raw) === 1 ? $raw : self::DEFAULT_FOLDER_ROOT;
    }

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

    private const SCHEMA_VERSION = 2;

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
                $table->string('gov_id', 50)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->primary(['place_id', 'tree_id']);
                $table->index('tree_id');
                $table->index('gov_id');
            });
            $didDdl = true;
        }

        // SCHEMA_VERSION=2: gov_id-Spalte nachrüsten für Bestandstabellen
        if ($schema->hasTable('ortsregister_place_meta')
            && !$schema->hasColumn('ortsregister_place_meta', 'gov_id')) {
            $schema->table('ortsregister_place_meta', function (Blueprint $table): void {
                $table->string('gov_id', 50)->nullable()->after('meta_data');
                $table->index('gov_id');
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
