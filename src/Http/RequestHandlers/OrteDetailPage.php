<?php

declare(strict_types=1);

namespace Ortsregister\Http\RequestHandlers;

use Ortsregister\Dto\DdbPlaceData;
use Ortsregister\Dto\WikimediaPlaceData;
use Ortsregister\OrtsregisterModule;
use Ortsregister\Repository\OrteRepository;
use Ortsregister\Service\DdbClient;
use Ortsregister\Service\GovHierarchyResolver;
use Ortsregister\Service\GovLinkingService;
use Ortsregister\Service\LocationReader;
use Ortsregister\Service\OperationBackup;
use Ortsregister\Service\PlaceDescriptionService;
use Ortsregister\Service\PlaceEventCounter;
use Ortsregister\Service\ArchionLinker;
use Ortsregister\Service\PlaceFolderScanner;
use Ortsregister\Service\PlaceKbListService;
use Ortsregister\Service\PlaceNotesService;
use Ortsregister\Service\PlaceTasksService;
use Ortsregister\Service\WikimediaPlaceClient;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * GET /tree/{tree}/orte/{place_id}
 *
 * Detailseite für einen einzelnen Ort.
 */
class OrteDetailPage extends AbstractOrtsregisterHandler
{
    public function __construct(
        private readonly OrteRepository       $orteRepository,
        private readonly GovLinkingService    $govLinking,
        private readonly GovHierarchyResolver $govHierarchy,
        private readonly PlaceEventCounter    $eventCounter,
        private readonly WikimediaPlaceClient $wikimedia,
        private readonly DdbClient            $ddb,
        private readonly PlaceFolderScanner   $folderScanner,
        private readonly PlaceNotesService    $notesService,
        private readonly ArchionLinker        $archionLinker,
        private readonly PlaceTasksService    $tasksService,
        private readonly PlaceKbListService   $kbService,
        private readonly OrtsregisterModule   $module,
        private readonly LocationReader       $locationReader = new LocationReader(),
        private readonly ?OperationBackup     $operationBackup = null,
        private readonly ?PlaceDescriptionService $descriptionService = null,
    ) {}

    protected function respond(
        ServerRequestInterface $request,
        ?Tree $tree
    ): ResponseInterface {
        $emptyCounts = ['BIRT' => 0, 'MARR' => 0, 'DEAT' => 0, 'OTHER' => 0, 'TOTAL' => 0];
        $emptyWiki   = WikimediaPlaceData::empty();
        $emptyDdb    = DdbPlaceData::empty();
        $defaults    = [
            'personen_visible' => $this->module->personenVisible(),
            'medien_visible'   => $this->module->medienVisible(),
            'bilder_visible'   => $this->module->bilderVisible(),
            'link_wikipedia'   => $this->module->linkWikipedia(),
            'link_matricula'   => $this->module->linkMatricula(),
            'link_archion'     => $this->module->linkArchion(),
            'link_archivpdb'   => $this->module->linkArchivportalD(),
            'link_ddb'         => $this->module->linkDdb(),
        ];

        if ($tree === null) {
            return $this->viewResponse($this->viewName('ort-detail'), array_merge([
                'title'        => I18N::translate('Ort'),
                'tree'         => null,
                'ort'          => null,
                'loc_records'  => [],
                'gov_geschwister' => [],
                'gov_kandidaten'  => [],
                'loc_undo_log_id' => null,
                'locev_undo_log_id' => null,
                'personen'     => [],
                'medien'       => [],
                'gov_id'             => null,
                'gov_chain'          => [],
                'gov_chain_current'  => [],
                'gov_hierarchy_mode' => OrtsregisterModule::DEFAULT_HIERARCHY_MODE,
                'event_counts' => $emptyCounts,
                'wiki'         => $emptyWiki,
                'ddb'          => $emptyDdb,
                'folder_files' => [],
                'note_slots'   => [],
                'archion_url'    => null,
                'archion_source' => null,
                'tasks'        => [],
                'task_counts'  => ['open' => 0, 'done' => 0],
                'kbs'          => [],
                'can_edit'     => false,
                'module'       => $this->module,
            ], $defaults));
        }

        $placeId = (int) ($request->getAttribute('place_id') ?? 0);

        $ort = $this->orteRepository->findeOrtById($tree, $placeId);

        if ($ort === null) {
            return $this->viewResponse($this->viewName('ort-detail'), array_merge([
                'title'        => I18N::translate('Ort nicht gefunden'),
                'tree'         => $tree,
                'ort'          => null,
                'loc_records'  => [],
                'gov_geschwister' => [],
                'gov_kandidaten'  => [],
                'loc_undo_log_id' => null,
                'locev_undo_log_id' => null,
                'personen'     => [],
                'medien'       => [],
                'gov_id'             => null,
                'gov_chain'          => [],
                'gov_chain_current'  => [],
                'gov_hierarchy_mode' => OrtsregisterModule::DEFAULT_HIERARCHY_MODE,
                'event_counts' => $emptyCounts,
                'wiki'         => $emptyWiki,
                'ddb'          => $emptyDdb,
                'folder_files' => [],
                'note_slots'   => [],
                'archion_url'    => null,
                'archion_source' => null,
                'tasks'        => [],
                'task_counts'  => ['open' => 0, 'done' => 0],
                'kbs'          => [],
                'can_edit'     => false,
                'module'       => $this->module,
            ], $defaults));
        }

        // Personen mit Ereignissen an diesem Ort
        $personen = $this->ladePersonen($tree, $placeId);

        // Medien die an diesem Ort hängen
        $medien = $this->ladeMedien($tree, $placeId);

        // GOV-Hierarchie: nur wenn verknüpft. API-Fehler dürfen die Seite nicht killen.
        // Modus aus Setting (historisch/aktuell/beide).
        $govId            = $this->govLinking->getLinkedGovId($tree, $placeId);
        $govChain         = [];          // historisch (partOfIds)
        $govChainCurrent  = [];          // aktuell    (locatedInIds)
        $hierarchyMode = $this->module->hierarchyMode();
        // IMMER beide Ketten laden — GOV liefert nicht für alle Orte locatedInIds,
        // dann muss die View graceful auf historische Kette zurückfallen können.
        // Cache (7d) sorgt dafür dass das nur beim ersten Request kostet.
        if ($govId !== null) {
            $mapStep = fn(array $step) => [
                'gov_id' => $step['obj']->govId,
                'name'   => $this->govHierarchy->germanNameOf($step['obj']),
                'begin'  => $step['begin'],
                'end'    => $step['end'],
            ];
            try {
                foreach ($this->govHierarchy->resolveWithEdges($govId, \Ortsregister\Service\GovHierarchyResolver::MODE_HISTORICAL) as $step) {
                    $govChain[] = $mapStep($step);
                }
            } catch (Throwable) {}
            try {
                foreach ($this->govHierarchy->resolveWithEdges($govId, \Ortsregister\Service\GovHierarchyResolver::MODE_CURRENT) as $step) {
                    $govChainCurrent[] = $mapStep($step);
                }
            } catch (Throwable) {}
        }

        // Ereignisse pro Tag (BIRT/MARR/DEAT/OTHER) — Mini-Parser über GEDCOM-Blobs.
        $eventCounts = $this->eventCounter->countFor($tree, $placeId, $ort->name);

        // Wikimedia-Lookup (Hauptbild + Galerie). 7d-Cache, Geo-Validation via GOV-Koord.
        // Niemals Page-killend — Service liefert immer einen DTO.
        $wiki = $emptyWiki;
        try {
            $wiki = $this->wikimedia->lookup($ort->name, $ort->breitengrad, $ort->laengengrad);
        } catch (Throwable) {
            // Stiller Fallback — leerer DTO
        }

        // DDB-Lookup (Treffer-Zahl + bis zu 6 Dokumente). Bei leerem API-Key → leer.
        $ddb = $emptyDdb;
        try {
            $ddb = $this->ddb->lookup($ort->name);
        } catch (Throwable) {
            // Stiller Fallback
        }

        // Filesystem-Ortsbilder/-Dateien aus media/<root>/<ortsname>/
        $folderFiles = [];
        try {
            $folderFiles = $this->folderScanner->scan($tree, $ort->name);
        } catch (Throwable) {
            // Stiller Fallback (z.B. Permission-Error)
        }

        // Markdown-Slots: Standard-3 + alle weiteren *.md aus dem Ortsordner.
        // Pro Slot {filename, title, placeholder, markdown, html, mtime}.
        $defaultSlots = [
            'notes.md'     => [
                'title'       => \Fisharebest\Webtrees\I18N::translate('Beschreibung'),
                'placeholder' => "# " . $ort->name . "\n\nKurze Beschreibung des Orts, historische/geografische Hinweise, Kirchen-/Pfarrei-Zugehörigkeit…",
                'person_picker' => false,
            ],
            // recherche.md ist nach Phase 3T (KB-Kacheln) KEIN Default-Slot mehr.
            // Wird als Custom-MD-Slot weiter unten angezeigt falls User-File existiert.
        ];
        $extraSlots = [];
        try {
            foreach ($this->notesService->scanMarkdownFiles($tree, $ort->name) as $foundFile) {
                if (!isset($defaultSlots[$foundFile])) {
                    $extraSlots[$foundFile] = [
                        'title'       => ucfirst(basename($foundFile, '.md')),
                        'placeholder' => '',
                    ];
                }
            }
        } catch (Throwable) {
            // bei Scan-Fehler — Standard-Slots reichen
        }
        $allSlots = $defaultSlots + $extraSlots;

        $noteSlots = [];
        foreach ($allSlots as $filename => $meta) {
            $markdown = '';
            $mtime    = 0;
            try {
                if ($filename === 'notes.md' && $this->descriptionService !== null) {
                    // Beschreibung: Original ist ab jetzt der _LOC NOTE. Datei nur noch
                    // Fallback, bis der Ort einmal übers Modul gespeichert hat (Migration).
                    $markdown = $this->descriptionService->read($tree, $ort->name)
                        ?? $this->notesService->read($tree, $ort->name, $filename)->markdown;
                    $mtime = 0; // _LOC-Notiz nutzt kein file-mtime-Locking
                } else {
                    $n        = $this->notesService->read($tree, $ort->name, $filename);
                    $markdown = $n->markdown;
                    $mtime    = $n->mtime;
                }
                $html = $this->notesService->render($markdown, $tree);
            } catch (Throwable) {
                $html = '';
            }
            $noteSlots[] = [
                'filename'      => $filename,
                'title'         => $meta['title'],
                'placeholder'   => $meta['placeholder'],
                'markdown'      => $markdown,
                'html'          => $html,
                'mtime'         => $mtime,
                // Default: Picker für Extra-Slots EINgeschaltet (User-Custom-MD), für notes.md aus
                'person_picker' => $meta['person_picker'] ?? ($filename !== 'notes.md'),
            ];
        }

        // Archion-Deep-Link: 1. per-place file, 2. global map, 3. auto via Koord.
        // Fehler dürfen die Seite nicht killen.
        $archionUrl    = null;
        $archionSource = null;
        try {
            $resolved = $this->archionLinker->forPlaceWithSource(
                $tree, $ort->name,
                $ort->breitengrad,
                $ort->laengengrad,
            );
            if ($resolved !== null) {
                $archionUrl    = $resolved['url'];
                $archionSource = $resolved['source'];
            }
        } catch (Throwable) {
            // stiller Fallback
        }

        // Strukturierte Aufgaben aus _tasks.json
        $tasks      = [];
        $taskCounts = ['open' => 0, 'done' => 0];
        try {
            foreach ($this->tasksService->read($tree, $ort->name) as $t) {
                $tasks[] = $t;
                $t->isOpen() ? $taskCounts['open']++ : $taskCounts['done']++;
            }
        } catch (Throwable) {}

        // Kirchenbücher pro Ort (Modul-eigene Liste + optional SOUR-verlinkt)
        $kbs = [];
        try {
            $rawKbs = $this->kbService->read($tree, $ort->name);
            // chronologisch sortieren (yearFrom asc, null ans Ende)
            usort($rawKbs, function ($a, $b) {
                $av = $a->yearFrom ?? PHP_INT_MAX;
                $bv = $b->yearFrom ?? PHP_INT_MAX;
                return $av <=> $bv;
            });
            foreach ($rawKbs as $kb) {
                // Tree-Titel hat Vorrang wenn SOUR verknüpft + existent
                $displayTitle = $kb->title;
                if ($kb->sourXref !== null) {
                    $sour = \Fisharebest\Webtrees\Registry::sourceFactory()->make($kb->sourXref, $tree);
                    if ($sour !== null) {
                        $displayTitle = strip_tags((string) $sour->fullName());
                    }
                }
                $kbs[] = [
                    'kb'            => $kb,
                    'display_title' => $displayTitle,
                    'has_log'       => $this->kbService->readLogbook($tree, $ort->name, $kb->id) !== '',
                ];
            }
        } catch (Throwable) {}

        // GEDCOM-L _LOC-Records mit passendem Namen (rein lesend, Identitäts-Schicht).
        // Nur ein Hinweis — Namensgleichheit ist Heuristik, keine harte Zuordnung.
        $locRecords = [];
        try {
            $locRecords = $this->locationReader->forPlaceName($tree, $ort->name);
        } catch (Throwable) {
            // Stiller Fallback — die Ortsseite darf daran nicht scheitern.
        }

        // Jüngster rückgängig-machbarer _LOC-Schreibvorgang an diesem Ort (Undo-Button).
        $locUndoLogId   = null;
        $locevUndoLogId = null;
        try {
            $locUndoLogId   = $this->operationBackup?->latestUndoable($tree->id(), 'loc_write', $placeId);
            $locevUndoLogId = $this->operationBackup?->latestUndoable($tree->id(), 'loc_event_link', $placeId);
        } catch (Throwable) {
            // Stiller Fallback — kein Undo-Button, kein Seitenfehler.
        }

        // Zeit-/Gebietsreform-Varianten desselben Orts (Achse C): andere Orte mit
        // derselben GOV-Kennung. Rein lesend — nur Querverweise, nichts geschrieben.
        $govGeschwister = [];
        try {
            $govGeschwister = $this->orteRepository->govGeschwister($tree, $placeId);
        } catch (Throwable) {
            // Stiller Fallback — kein Hinweis, kein Seitenfehler.
        }

        // Kandidaten für Sammel-Verknüpfung: gleichnamige, noch nicht verknüpfte Orte.
        $govKandidaten = [];
        try {
            $govKandidaten = $this->orteRepository->govVerknuepfungsKandidaten($tree, $placeId);
        } catch (Throwable) {
            // Stiller Fallback — kein Vorschlag, kein Seitenfehler.
        }

        return $this->viewResponse($this->viewName('ort-detail'), array_merge([
            'title'        => $ort->name,
            'tree'         => $tree,
            'ort'          => $ort,
            'loc_records'  => $locRecords,
            'loc_undo_log_id' => $locUndoLogId,
            'locev_undo_log_id' => $locevUndoLogId,
            'gov_geschwister' => $govGeschwister,
            'gov_kandidaten'  => $govKandidaten,
            'personen'     => $personen,
            'medien'       => $medien,
            'gov_id'             => $govId,
            'gov_chain'          => $govChain,
            'gov_chain_current'  => $govChainCurrent,
            'gov_hierarchy_mode' => $hierarchyMode,
            'place_id'           => $placeId,
            'event_counts' => $eventCounts,
            'wiki'         => $wiki,
            'ddb'          => $ddb,
            'folder_files' => $folderFiles,
            'note_slots'   => $noteSlots,
            'archion_url'    => $archionUrl,
            'archion_source' => $archionSource,
            'tasks'        => $tasks,
            'task_counts'  => $taskCounts,
            'kbs'          => $kbs,
            'can_edit'     => \Fisharebest\Webtrees\Auth::isEditor($tree),
            'module'       => $this->module,
        ], $defaults));
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
