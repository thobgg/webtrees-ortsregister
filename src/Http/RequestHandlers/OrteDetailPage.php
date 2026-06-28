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
use Ortsregister\Service\PlaceEventCounter;
use Ortsregister\Service\ArchionLinker;
use Ortsregister\Service\PlaceFolderScanner;
use Ortsregister\Service\PlaceNotesService;
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
        private readonly OrtsregisterModule   $module,
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
                'personen'     => [],
                'medien'       => [],
                'gov_id'       => null,
                'gov_chain'    => [],
                'event_counts' => $emptyCounts,
                'wiki'         => $emptyWiki,
                'ddb'          => $emptyDdb,
                'folder_files' => [],
                'note_slots'   => [],
                'archion_url'  => null,
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
                'personen'     => [],
                'medien'       => [],
                'gov_id'       => null,
                'gov_chain'    => [],
                'event_counts' => $emptyCounts,
                'wiki'         => $emptyWiki,
                'ddb'          => $emptyDdb,
                'folder_files' => [],
                'note_slots'   => [],
                'archion_url'  => null,
                'can_edit'     => false,
                'module'       => $this->module,
            ], $defaults));
        }

        // Personen mit Ereignissen an diesem Ort
        $personen = $this->ladePersonen($tree, $placeId);

        // Medien die an diesem Ort hängen
        $medien = $this->ladeMedien($tree, $placeId);

        // GOV-Hierarchie: nur wenn verknüpft. API-Fehler dürfen die Seite nicht killen.
        $govId    = $this->govLinking->getLinkedGovId($tree, $placeId);
        $govChain = [];
        if ($govId !== null) {
            try {
                foreach ($this->govHierarchy->resolveWithEdges($govId) as $step) {
                    $govChain[] = [
                        'gov_id' => $step['obj']->govId,
                        'name'   => $this->govHierarchy->germanNameOf($step['obj']),
                        'begin'  => $step['begin'],
                        'end'    => $step['end'],
                    ];
                }
            } catch (Throwable) {
                $govChain = []; // Stiller Fallback — View zeigt dann nur die ID
            }
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
            'recherche.md' => [
                'title'       => \Fisharebest\Webtrees\I18N::translate('Recherche'),
                'placeholder' => "## Kirchenbücher gesichtet\n\n- [ ] Taufen JJJJ–JJJJ\n- [ ] Heiraten JJJJ–JJJJ\n- [ ] Beerdigungen JJJJ–JJJJ\n\n## Offene Punkte\n\n- [ ] \n\n## Relevante Personen\n\n- [Hans Müller](indi:I123) — *Vater unklar, KB Taufe 1715 prüfen*\n",
                'person_picker' => true,
            ],
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
            try {
                $n    = $this->notesService->read($tree, $ort->name, $filename);
                $html = $this->notesService->render($n->markdown, $tree);
            } catch (Throwable) {
                $n    = \Ortsregister\Dto\PlaceNotes::empty();
                $html = '';
            }
            $noteSlots[] = [
                'filename'      => $filename,
                'title'         => $meta['title'],
                'placeholder'   => $meta['placeholder'],
                'markdown'      => $n->markdown,
                'html'          => $html,
                'mtime'         => $n->mtime,
                // Default: Picker für Extra-Slots EINgeschaltet (User-Custom-MD), für notes.md aus
                'person_picker' => $meta['person_picker'] ?? ($filename !== 'notes.md'),
            ];
        }

        // Archion-Deep-Link aus _archion.json (oder null wenn keine Konfig)
        // Fehlende Pflichtfelder werfen Exception → stille Fallback auf Generic-Suche
        $archionUrl = null;
        try {
            $archionUrl = $this->archionLinker->forPlace($tree, $ort->name);
        } catch (Throwable) {
            $archionUrl = null;
        }

        return $this->viewResponse($this->viewName('ort-detail'), array_merge([
            'title'        => $ort->name,
            'tree'         => $tree,
            'ort'          => $ort,
            'personen'     => $personen,
            'medien'       => $medien,
            'gov_id'       => $govId,
            'gov_chain'    => $govChain,
            'place_id'     => $placeId,
            'event_counts' => $eventCounts,
            'wiki'         => $wiki,
            'ddb'          => $ddb,
            'folder_files' => $folderFiles,
            'note_slots'   => $noteSlots,
            'archion_url'  => $archionUrl,
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
