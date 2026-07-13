<?php

declare(strict_types=1);

namespace Ortsregister;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Http\RequestHandlers\AdminConfigPage;
use Ortsregister\Http\RequestHandlers\CoordinateImportPage;
use Ortsregister\Http\RequestHandlers\PlaceFileServe;
use Ortsregister\Http\RequestHandlers\PlaceNotesSave;
use Ortsregister\Http\RequestHandlers\PlaceNotesToggleTask;
use Ortsregister\Http\RequestHandlers\PlaceKbsUpdate;
use Ortsregister\Http\RequestHandlers\PlaceTasksUpdate;
use Ortsregister\Http\RequestHandlers\GovLinkPage;
use Ortsregister\Http\RequestHandlers\MergeExecute;
use Ortsregister\Http\RequestHandlers\MergeModalPage;
use Ortsregister\Http\RequestHandlers\MergeUndo;
use Ortsregister\Http\RequestHandlers\LocWritePreview;
use Ortsregister\Http\RequestHandlers\LocWriteExecute;
use Ortsregister\Http\RequestHandlers\LocWriteUndo;
use Ortsregister\Http\RequestHandlers\LocEventLinkPreview;
use Ortsregister\Http\RequestHandlers\LocEventLinkExecute;
use Ortsregister\Http\RequestHandlers\LocEventLinkUndo;
use Ortsregister\Http\RequestHandlers\GovLinkSiblings;
use Ortsregister\Http\RequestHandlers\RenameExecute;
use Ortsregister\Http\RequestHandlers\RenameModalPage;
use Ortsregister\Http\RequestHandlers\OrteDataTable;
use Ortsregister\Http\RequestHandlers\OrteDetailPage;
use Ortsregister\Http\RequestHandlers\OrteKarte;
use Ortsregister\Http\RequestHandlers\OrtePage;
use Ortsregister\Http\RequestHandlers\SetPlaceFilterMode;
use Ortsregister\Service\CoordinateImportService;
use Ortsregister\Service\DdbClient;
use Ortsregister\Service\GedcomCoordinateExtractor;
use Ortsregister\Service\GedcomPlaceManipulator;
use Ortsregister\Service\PlaceRecordMutator;
use Ortsregister\Service\GovApiClient;
use Ortsregister\Service\GovHierarchyResolver;
use Ortsregister\Service\GovLinkingService;
use Ortsregister\Service\PlaceEventCounter;
use Ortsregister\Service\ArchionLinker;
use Ortsregister\Service\ArchionParishLookup;
use Ortsregister\Service\LocationReader;
use Ortsregister\Service\LocationWriter;
use Ortsregister\Service\LocationEventLinker;
use Ortsregister\Service\OperationBackup;
use Ortsregister\Service\PlaceFolderLocator;
use Ortsregister\Service\PlaceFolderScanner;
use Ortsregister\Service\PlaceSidecarInventory;
use Ortsregister\Service\PlaceSidecarMerger;
use Ortsregister\Service\PlaceNotesService;
use Ortsregister\Service\PlaceKbListService;
use Ortsregister\Service\PlaceTasksService;
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
    public const SETTING_HIERARCHY_MODE   = 'hierarchy_mode';
    public const SETTING_ARCHION_AUTO_KM  = 'archion_auto_km';

    public const HIERARCHY_MODE_HISTORICAL = 'historical';
    public const HIERARCHY_MODE_CURRENT    = 'current';
    public const HIERARCHY_MODE_BOTH       = 'both';

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
    public const DEFAULT_HIERARCHY_MODE   = self::HIERARCHY_MODE_HISTORICAL;
    public const DEFAULT_ARCHION_AUTO_KM  = 10;

    public function title(): string { return I18N::translate('Ortsregister'); }
    public function description(): string { return I18N::translate('Ortsregister mit visueller Landing-Page, Medien-Verknüpfung und (geplant) GOV-Integration.'); }
    public function customModuleAuthorName(): string { return 'Thomas Bugge'; }
    public function customModuleVersion(): string { return '1.6.0'; }
    public function customModuleSupportUrl(): string { return ''; }

    /**
     * Modul-spezifische Uebersetzungen aus resources/lang/<lang>.mo (bevorzugt) oder .po.
     * webtrees ruft dies pro Sprache auf und merged das Ergebnis in den Translator.
     * Sprachtag ist z.B. "nl", "de", "en-GB" — fallback auf 2-Buchstaben-Praefix.
     *
     * @return array<string,string>
     */
    public function customTranslations(string $language): array
    {
        $dir = __DIR__ . '/../resources/lang/';
        foreach ([$language, explode('-', $language)[0]] as $tag) {
            foreach (['mo', 'po'] as $ext) {
                $file = $dir . $tag . '.' . $ext;
                if (is_file($file)) {
                    return (new \Fisharebest\Localization\Translation($file))->asArray();
                }
            }
        }
        return [];
    }

    public function boot(): void
    {
        $this->migrateDatabase();
        $this->registerServices();
        View::registerNamespace(self::MODULE_NAME, $this->resourcesFolder() . 'views/');

        // Orts-Aufgaben (#7): `_LOC:_TODO` als Custom-Tags registrieren — exakt das
        // Muster des Core-`ResearchTaskModule` (das nur INDI/FAM registriert). Damit
        // zeigt auch die NATIVE _LOC-Seite die Aufgaben strukturiert an, statt sie
        // als unbekannte Tags zu behandeln.
        Registry::elementFactory()->registerTags([
            '_LOC:_TODO'          => new \Fisharebest\Webtrees\Elements\ResearchTask(I18N::translate('Forschungsaufgabe')),
            '_LOC:_TODO:DATE'     => new \Fisharebest\Webtrees\Elements\DateValueToday(I18N::translate('Datum')),
            '_LOC:_TODO:NOTE'     => new \Fisharebest\Webtrees\Elements\NoteStructure(I18N::translate('Notiz')),
            '_LOC:_TODO:_WT_USER' => new \Fisharebest\Webtrees\Elements\WebtreesUser(I18N::translate('Bearbeiter')),
            '_LOC:_TODO:STAT'     => new \Fisharebest\Webtrees\Elements\ResearchTaskStatus(I18N::translate('Status')),
            '_LOC:_TODO:_UID'     => new \Fisharebest\Webtrees\Elements\CustomElement(I18N::translate('Eindeutige Kennung')),
        ]);
        Registry::elementFactory()->make('_LOC')->subtag('_TODO', '0:M');

        $router = Registry::routeFactory()->routeMap();

        $router->get('ortsregister.orte',          '/tree/{tree}/orte',                OrtePage::class);
        $router->get('ortsregister.orte.data',     '/tree/{tree}/orte/data',           OrteDataTable::class);
        $router->get('ortsregister.orte.karte',    '/tree/{tree}/orte/karte',          OrteKarte::class);
        $router->get('ortsregister.merge.preview', '/tree/{tree}/orte/merge/preview',  MergeModalPage::class);
        $router->get('ortsregister.merge.execute', '/tree/{tree}/orte/merge/execute',  MergeExecute::class)
               ->allows('POST');
        $router->get('ortsregister.merge.undo',    '/tree/{tree}/orte/merge/undo',     MergeUndo::class)
               ->allows('POST');
        $router->get('ortsregister.rename.preview','/tree/{tree}/orte/rename/preview', RenameModalPage::class);
        $router->get('ortsregister.rename.execute','/tree/{tree}/orte/rename/execute', RenameExecute::class)
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
        $router->post('ortsregister.tasks.update',       '/tree/{tree}/orte/{place_id}/aufgaben',       PlaceTasksUpdate::class);
        $router->post('ortsregister.kbs.update',         '/tree/{tree}/orte/{place_id}/kbs',            PlaceKbsUpdate::class);
        $router->get('ortsregister.loc.preview',         '/tree/{tree}/orte/{place_id}/loc-write/preview', LocWritePreview::class);
        $router->post('ortsregister.loc.write',          '/tree/{tree}/orte/{place_id}/loc-write',         LocWriteExecute::class);
        $router->post('ortsregister.loc.undo',           '/tree/{tree}/orte/{place_id}/loc-write/undo',    LocWriteUndo::class);
        $router->get('ortsregister.locev.preview',       '/tree/{tree}/orte/{place_id}/loc-events/preview', LocEventLinkPreview::class);
        $router->post('ortsregister.locev.write',        '/tree/{tree}/orte/{place_id}/loc-events',         LocEventLinkExecute::class);
        $router->post('ortsregister.locev.undo',         '/tree/{tree}/orte/{place_id}/loc-events/undo',    LocEventLinkUndo::class);
        $router->post('ortsregister.gov.siblings',       '/tree/{tree}/orte/{place_id}/gov-siblings',      GovLinkSiblings::class);
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
        // PlaceFolderLocator: kanonische Ort->Ordner-Naht, von den Sidecar-Services geteilt.
        $folderLocator = new PlaceFolderLocator($this->folderRoot());
        // PlaceOperationService + Sidecar-Merge-Stack werden am ENDE registriert
        // (sie brauchen die weiter unten gesetzten Sidecar-/GOV-Services).
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
            new GovLinkingService(
                $container->get(GovApiClient::class),
                $container->get(ApcuCacheService::class),
                // _LOC-Anker beim GOV-Verknüpfen (Doktrin: Kennung in den Baum, nicht DB-only).
                // Inline gebaut, weil der LOC-Stack erst weiter unten registriert wird — identische
                // Bauweise (arg-loser Reader, gleicher Backup-Ordner), stateless, also unkritisch.
                new LocationWriter(new LocationReader(), new OperationBackup(__DIR__ . '/../backups')),
                new \Ortsregister\Service\LocBindingService(new LocationReader()),
            ),
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
            new PlaceFolderScanner($folderLocator),
        );
        $container->set(
            PlaceNotesService::class,
            new PlaceNotesService($folderLocator),
        );
        $container->set(
            ArchionParishLookup::class,
            new ArchionParishLookup(__DIR__ . '/../resources/data/archion-parishes.json'),
        );
        $container->set(
            ArchionLinker::class,
            new ArchionLinker(
                $folderLocator,
                $container->get(ArchionParishLookup::class),
                (float) $this->archionAutoDistanceKm(),
            ),
        );
        $container->set(
            PlaceTasksService::class,
            new PlaceTasksService($folderLocator),
        );
        $container->set(
            PlaceKbListService::class,
            new PlaceKbListService($folderLocator),
        );
        // Sidecar-Merge-Stack (Phase-4-GATE): nutzt die oben registrierten
        // Sidecar-Services + GovLinkingService, daher erst hier.
        $container->set(
            PlaceFolderLocator::class,
            $folderLocator,
        );
        $container->set(
            PlaceSidecarMerger::class,
            new PlaceSidecarMerger($container->get(PlaceFolderLocator::class)),
        );
        $container->set(
            PlaceSidecarInventory::class,
            new PlaceSidecarInventory(
                $container->get(PlaceNotesService::class),
                $container->get(PlaceTasksService::class),
                $container->get(PlaceKbListService::class),
                $container->get(PlaceFolderScanner::class),
                $container->get(GovLinkingService::class),
            ),
        );
        $container->set(
            PlaceOperationService::class,
            new PlaceOperationService(
                $container->get(ApcuCacheService::class),
                $container->get(GedcomPlaceManipulator::class),
                __DIR__ . '/../backups',
                $container->get(PlaceSidecarMerger::class),
                $container->get(PlaceSidecarInventory::class),
                new PlaceRecordMutator($container->get(GedcomPlaceManipulator::class)),
                new LocationReader(),
            ),
        );
        // _LOC-Writer-Stack (W1): Identitäts-Record schreiben/aktualisieren.
        $container->set(LocationReader::class, new LocationReader());
        $container->set(OperationBackup::class, new OperationBackup(__DIR__ . '/../backups'));
        $container->set(
            LocationWriter::class,
            new LocationWriter(
                $container->get(LocationReader::class),
                $container->get(OperationBackup::class),
            ),
        );
        // Bindung Ort↔_LOC (gegen die Blattnamen-Falle bei gleichnamigen Orten wie „Friedhof").
        $container->set(
            \Ortsregister\Service\LocBindingService::class,
            new \Ortsregister\Service\LocBindingService($container->get(LocationReader::class)),
        );
        // Ortsbeschreibung (notes.md) → `_LOC` NOTE (Daten-Doktrin: Text in den Baum).
        $container->set(
            \Ortsregister\Service\PlaceDescriptionService::class,
            new \Ortsregister\Service\PlaceDescriptionService(
                $container->get(\Ortsregister\Service\LocBindingService::class),
                $container->get(LocationReader::class),
                $container->get(LocationWriter::class),
                $container->get(OperationBackup::class),
            ),
        );
        $container->set(
            PlaceNotesSave::class,
            new PlaceNotesSave(
                $container->get(PlaceNotesService::class),
                $container->get(\Ortsregister\Service\PlaceDescriptionService::class),
            ),
        );
        // Orts-Aufgaben (#7): Heimat = `_LOC:_TODO` im Baum; JSON nur noch Migrations-Fallback.
        $container->set(
            \Ortsregister\Service\PlaceTasksLocStore::class,
            new \Ortsregister\Service\PlaceTasksLocStore(
                $container->get(\Ortsregister\Service\LocBindingService::class),
                new \Ortsregister\Service\LocTodoMapper(),
                $container->get(OperationBackup::class),
                $container->get(PlaceTasksService::class),
                $container->get(PlaceFolderLocator::class),
            ),
        );
        $container->set(
            \Ortsregister\Http\RequestHandlers\PlaceTasksUpdate::class,
            new \Ortsregister\Http\RequestHandlers\PlaceTasksUpdate(
                $container->get(\Ortsregister\Service\PlaceTasksLocStore::class),
            ),
        );
        $container->set(
            LocWritePreview::class,
            new LocWritePreview(
                $container->get(LocationWriter::class),
                $container->get(\Ortsregister\Repository\OrteRepository::class),
                $container->get(GovLinkingService::class),
                $container->get(\Ortsregister\Service\LocBindingService::class),
            ),
        );
        $container->set(
            LocWriteExecute::class,
            new LocWriteExecute(
                $container->get(LocationWriter::class),
                $container->get(\Ortsregister\Repository\OrteRepository::class),
                $container->get(GovLinkingService::class),
                $container->get(OperationBackup::class),
                $container->get(\Ortsregister\Service\LocBindingService::class),
            ),
        );
        $container->set(
            LocWriteUndo::class,
            new LocWriteUndo(
                $container->get(LocationWriter::class),
                $container->get(OperationBackup::class),
            ),
        );
        // _LOC-Ereignis-Zeiger-Stack (W2): `3 _LOC @x@` unter Ereignis-PLACs setzen.
        $container->set(
            LocationEventLinker::class,
            new LocationEventLinker(
                $container->get(LocationReader::class),
                $container->get(OperationBackup::class),
            ),
        );
        $container->set(
            LocEventLinkPreview::class,
            new LocEventLinkPreview(
                $container->get(LocationEventLinker::class),
                $container->get(\Ortsregister\Repository\OrteRepository::class),
                $container->get(\Ortsregister\Service\LocBindingService::class),
            ),
        );
        $container->set(
            LocEventLinkExecute::class,
            new LocEventLinkExecute(
                $container->get(LocationEventLinker::class),
                $container->get(\Ortsregister\Repository\OrteRepository::class),
                $container->get(OperationBackup::class),
                $container->get(\Ortsregister\Service\LocBindingService::class),
            ),
        );
        $container->set(
            LocEventLinkUndo::class,
            new LocEventLinkUndo(
                $container->get(LocationEventLinker::class),
                $container->get(OperationBackup::class),
            ),
        );
        $container->set(
            GovLinkSiblings::class,
            new GovLinkSiblings(
                $container->get(GovLinkingService::class),
                $container->get(\Ortsregister\Repository\OrteRepository::class),
            ),
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
                $container->get(\Ortsregister\Service\PlaceTasksLocStore::class),
                $container->get(PlaceKbListService::class),
                $this,
                new LocationReader(),
                $container->get(OperationBackup::class),
                $container->get(\Ortsregister\Service\PlaceDescriptionService::class),
                $container->get(\Ortsregister\Service\LocBindingService::class),
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
    public function archionAutoDistanceKm(): int
    {
        return max(1, min(100, (int) $this->getPreference(self::SETTING_ARCHION_AUTO_KM, (string) self::DEFAULT_ARCHION_AUTO_KM)));
    }
    public function hierarchyMode(): string
    {
        $raw = $this->getPreference(self::SETTING_HIERARCHY_MODE, self::DEFAULT_HIERARCHY_MODE);
        return in_array($raw, [
            self::HIERARCHY_MODE_HISTORICAL,
            self::HIERARCHY_MODE_CURRENT,
            self::HIERARCHY_MODE_BOTH,
        ], true) ? $raw : self::DEFAULT_HIERARCHY_MODE;
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
        // SCHNELLPFAD (behebt „Lock wait timeout exceeded", Issue Hermann):
        // migrateDatabase() läuft bei JEDEM Request (boot). Vorher schrieb es am Ende
        // UNBEDINGT setPreference('SCHEMA_VERSION') — ein updateOrInsert, das eine
        // Schreib-Sperre auf EINER module_setting-Zeile nimmt. Bei langen Transaktionen
        // (z.B. Person anlegen) + Nebenläufigkeit wartete ein zweiter Request 50 s auf
        // diese Sperre → Timeout. Ist die Version schon aktuell, jetzt nur noch ein
        // billiges getPreference (SELECT), KEIN Schreibzugriff.
        // Sicher: SCHEMA_VERSION wird ausschließlich am Ende NACH erfolgreicher DDL
        // gesetzt (DDL-Fehler wirft vorher) — „Version gesetzt, Tabellen fehlen" kann
        // also nicht auftreten.
        if ((int) $this->getPreference('SCHEMA_VERSION', '0') === self::SCHEMA_VERSION) {
            return;
        }

        $schema = DB::schema();
        $didDdl = false;

        // Idempotente Migration über hasTable() (nur im Upgrade-Fall erreicht).
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
