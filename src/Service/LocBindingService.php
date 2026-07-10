<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

/**
 * Bindung Ort (place_id) ↔ `_LOC`-Record — die Antwort auf die Blattnamen-Falle:
 * Bei tief strukturierten Bäumen (Friedhof/Kirche/Straße als eigene Orte, wie bei
 * Hermann) teilen sich VERSCHIEDENE reale Orte denselben Blattnamen („Friedhof").
 * Ein reiner Namens-Match würde Beschreibungen/Aufgaben zwischen ihnen vermischen.
 *
 * Auflösung in fester Prioritätsfolge:
 *   1. Explizite Bindung — `meta_data.loc_xref` in ortsregister_place_meta
 *      (wird bei jeder erfolgreichen Auflösung persistiert; DB ist hier nur Cache:
 *      aus GOV-Kennung bzw. eindeutigem Namen jederzeit neu ableitbar).
 *   2. GOV-Kennung — Ort ist GOV-verknüpft und genau EIN `_LOC` trägt dieselbe
 *      `1 _GOV` → autoritativ.
 *   3. Blattname — nur wenn UNVERWECHSELBAR: genau ein `_LOC` mit dem Namen UND
 *      der Blattname kommt im Baum nur einmal vor.
 *   4. sonst: nichts (Caller legt bewusst einen NEUEN `_LOC` an, statt einen
 *      fremden gleichnamigen zu kapern).
 */
final class LocBindingService
{
    public function __construct(
        private readonly LocationReader $reader,
    ) {}

    /**
     * Gebundenen `_LOC` auflösen (ohne anzulegen). Persistiert die Bindung,
     * wenn sie über GOV/Namen neu gefunden wurde.
     */
    public function resolve(Tree $tree, int $placeId, string $leaf): ?GedcomRecord
    {
        // 1. Explizite Bindung
        $xref = $this->storedXref($tree, $placeId);
        if ($xref !== null) {
            $record = Registry::locationFactory()->make($xref, $tree);
            if ($record !== null) {
                return $record;
            }
            // Bindung zeigt ins Leere (Record gelöscht) → neu auflösen.
        }

        // 2. GOV-Kennung (autoritativ)
        $govId = $this->placeGovId($tree, $placeId);
        if ($govId !== null) {
            $matches = $this->reader->forGovId($tree, $govId);
            if (count($matches) === 1) {
                $record = Registry::locationFactory()->make($matches[0]->xref, $tree);
                if ($record !== null) {
                    $this->bind($tree, $placeId, $record->xref());
                    return $record;
                }
            }
        }

        // 3. Blattname — nur wenn beidseitig eindeutig
        $byName = $this->reader->forPlaceName($tree, $leaf);
        if (count($byName) === 1 && $this->leafIsUniqueAmongPlaces($tree, $leaf)) {
            $record = Registry::locationFactory()->make($byName[0]->xref, $tree);
            if ($record !== null) {
                $this->bind($tree, $placeId, $record->xref());
                return $record;
            }
        }

        return null;
    }

    /**
     * Wie resolve(), legt aber bei Bedarf einen minimalen `_LOC` an und bindet ihn.
     * Der Neu-Anlage-Fall ist gewollt: lieber ein eigener Record pro realem Ort
     * als ein gekaperter gleichnamiger.
     */
    public function resolveOrCreate(Tree $tree, int $placeId, string $leaf): GedcomRecord
    {
        $record = $this->resolve($tree, $placeId, $leaf);
        if ($record !== null) {
            return $record;
        }
        $record = $tree->createRecord("0 @@ _LOC\n1 NAME " . strtr(trim($leaf), ["\n" => "\n2 CONT "]));
        $this->bind($tree, $placeId, $record->xref());
        return $record;
    }

    /**
     * Bindung persistieren (meta_data.loc_xref). Lässt die gov_id-Spalte unangetastet.
     */
    public function bind(Tree $tree, int $placeId, string $xref): void
    {
        $existing = DB::table('ortsregister_place_meta')
            ->where('tree_id',  '=', $tree->id())
            ->where('place_id', '=', $placeId)
            ->select(['meta_data'])
            ->first();

        $meta = [];
        if ($existing !== null && $existing->meta_data !== null && (string) $existing->meta_data !== '') {
            try {
                $decoded = json_decode((string) $existing->meta_data, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            } catch (\JsonException) {
                // kaputtes JSON → überschreiben
            }
        }
        if (($meta['loc_xref'] ?? null) === $xref && $existing !== null) {
            return; // schon gebunden
        }
        $meta['loc_xref'] = $xref;
        $json = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if ($existing === null) {
            DB::table('ortsregister_place_meta')->insert([
                'tree_id'   => $tree->id(),
                'place_id'  => $placeId,
                'gov_id'    => null,
                'meta_data' => $json,
            ]);
        } else {
            DB::table('ortsregister_place_meta')
                ->where('tree_id',  '=', $tree->id())
                ->where('place_id', '=', $placeId)
                ->update(['meta_data' => $json]);
        }
    }

    // ---------------------------------------------------------------
    // Intern
    // ---------------------------------------------------------------

    private function storedXref(Tree $tree, int $placeId): ?string
    {
        $raw = DB::table('ortsregister_place_meta')
            ->where('tree_id',  '=', $tree->id())
            ->where('place_id', '=', $placeId)
            ->value('meta_data');
        if ($raw === null || (string) $raw === '') {
            return null;
        }
        try {
            $meta = json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $xref = is_array($meta) ? ($meta['loc_xref'] ?? null) : null;
        return is_string($xref) && $xref !== '' ? $xref : null;
    }

    private function placeGovId(Tree $tree, int $placeId): ?string
    {
        $govId = DB::table('ortsregister_place_meta')
            ->where('tree_id',  '=', $tree->id())
            ->where('place_id', '=', $placeId)
            ->value('gov_id');
        return $govId !== null && (string) $govId !== '' ? (string) $govId : null;
    }

    /** Kommt der Blattname im Baum genau einmal vor? (Sonst wäre der Namens-Match ein Kapern-Risiko.) */
    private function leafIsUniqueAmongPlaces(Tree $tree, string $leaf): bool
    {
        return DB::table('places')
            ->where('p_file',  '=', $tree->id())
            ->where('p_place', '=', trim($leaf))
            ->count() === 1;
    }
}
