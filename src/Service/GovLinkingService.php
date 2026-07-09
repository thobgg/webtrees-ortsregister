<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Dto\GovObject;
use Ortsregister\Dto\LocWritePlan;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\PlaceLocation;
use Fisharebest\Webtrees\Tree;
use RuntimeException;
use Throwable;

/**
 * Verknüpft webtrees-Places mit GOV-IDs und cached die GOV-Antwort
 * in ortsregister_place_meta (gov_id-Spalte + meta_data-JSON).
 */
class GovLinkingService
{
    public function __construct(
        private readonly GovApiClient $govClient,
        private readonly ?ApcuCacheService $cache = null,
        private readonly ?LocationWriter $locationWriter = null,
    ) {}

    /**
     * Liest die aktuell verknüpfte GOV-ID für einen Place.
     */
    public function getLinkedGovId(Tree $tree, int $placeId): ?string
    {
        $row = DB::table('ortsregister_place_meta')
            ->where('tree_id',  '=', $tree->id())
            ->where('place_id', '=', $placeId)
            ->select(['gov_id'])
            ->first();
        return $row !== null && $row->gov_id !== null ? (string) $row->gov_id : null;
    }

    /**
     * Setzt eine GOV-ID für einen Place, validiert vorher via GOV-API.
     * Wirft RuntimeException wenn ID nicht existiert.
     */
    public function link(Tree $tree, int $placeId, string $govId): GovObject
    {
        $obj = $this->govClient->getObject($govId);
        if ($obj === null) {
            throw new RuntimeException('GOV-ID nicht gefunden oder API-Fehler: ' . $govId);
        }

        $this->upsertMeta($tree, $placeId, $obj->govId, [
            'gov' => [
                'linked_at' => date('c'),
                'name'      => $obj->primaryName,
                'types'     => $obj->typeIds,
            ],
        ]);

        // GOV-Koordinaten additiv nach place_location übernehmen (gap-fill only),
        // damit der Ort auf der Karte erscheint UND der _LOC-Writer ein MAP schreiben
        // kann. Beigabe zur Verknüpfung: ein Fehler hier darf den Link nicht killen.
        if ($obj->hasCoordinates()) {
            try {
                $this->fillCoordinates($tree, $placeId, (float) $obj->latitude, (float) $obj->longitude);
            } catch (Throwable) {
                // Koordinaten-Übernahme fehlgeschlagen — Verknüpfung bleibt trotzdem gültig.
            }
        }

        // Daten-Doktrin: die GOV-Kennung ist erhaltenswert und nicht auto-regenerierbar,
        // darf also nicht DB-only bleiben. Additiv (gap-fill) auch in den `_LOC` schreiben,
        // damit sie im Baum/Export mitreist — place_meta bleibt dann nur Cache. Best-effort:
        // ohne Auto-Accept (oder bei Mehrdeutigkeit) übersprungen, die Verknüpfung bleibt gültig.
        try {
            $this->anchorGovInLoc($tree, $placeId, $obj);
        } catch (Throwable) {
            // _LOC-Anker fehlgeschlagen — Verknüpfung (place_meta) bleibt trotzdem gültig.
        }

        // OrtDto ist gecacht (gov_id + Koordinaten) — nach dem Schreiben leeren,
        // sonst zeigen Ortsseite und _LOC-Vorschau bis zu 20 Min alte Daten.
        $this->cache?->flush();

        return $obj;
    }

    /**
     * Schreibt die GOV-Kennung (+ vorhandene Koordinaten) additiv in den `_LOC` des Orts
     * über den getesteten W1-Writer: legt bei Bedarf einen minimalen `_LOC` an, füllt nur
     * Lücken, überschreibt nie. Mehrere gleichnamige `_LOC` (AMBIGUOUS) → nicht raten,
     * überspringen. Ohne Writer (nicht verdrahtet) No-op.
     */
    private function anchorGovInLoc(Tree $tree, int $placeId, GovObject $obj): void
    {
        if ($this->locationWriter === null) {
            return;
        }
        $leaf = DB::table('places')
            ->where('p_id',   '=', $placeId)
            ->where('p_file', '=', $tree->id())
            ->value('p_place');
        if ($leaf === null || (string) $leaf === '') {
            return;
        }
        $lat  = $obj->hasCoordinates() ? (float) $obj->latitude  : null;
        $lon  = $obj->hasCoordinates() ? (float) $obj->longitude : null;
        $plan = $this->locationWriter->plan($tree, $placeId, (string) $leaf, $obj->govId, $lat, $lon);

        if ($plan->action === LocWritePlan::ACTION_AMBIGUOUS) {
            return; // mehrere passende _LOC — nicht automatisch entscheiden
        }
        if ($plan->willWrite()) {
            $this->locationWriter->execute($tree, $plan); // wirft ohne Auto-Accept → vom Caller gefangen
        }
    }

    /**
     * Schreibt Koordinaten in webtrees' `place_location` (Standard-Gazetteer),
     * nur wenn dort noch keine stehen — bestehende werden nie überschrieben.
     * `PlaceLocation(...)->id()` legt fehlende Hierarchie-Einträge selbst an.
     */
    private function fillCoordinates(Tree $tree, int $placeId, float $lat, float $lon): void
    {
        $path = $this->fullPlacePath($tree, $placeId);
        if ($path === '') {
            return;
        }
        $id       = (new PlaceLocation($path))->id();
        $existing  = DB::table('place_location')->where('id', '=', $id)->first(['latitude', 'longitude']);
        if ($existing !== null && $existing->latitude !== null && $existing->longitude !== null) {
            return;
        }
        DB::table('place_location')->where('id', '=', $id)->update(['latitude' => $lat, 'longitude' => $lon]);
    }

    /** Vollständiger Komma-Pfad („Blatt, Eltern, …, Land") zu einer place_id. */
    private function fullPlacePath(Tree $tree, int $placeId): string
    {
        $parts = [];
        $id    = $placeId;
        for ($guard = 0; $id > 0 && $guard < 20; $guard++) {
            $row = DB::table('places')
                ->where('p_id', '=', $id)
                ->where('p_file', '=', $tree->id())
                ->first(['p_place', 'p_parent_id']);
            if ($row === null) {
                break;
            }
            $parts[] = (string) $row->p_place;
            $id      = (int) $row->p_parent_id;
        }
        return implode(', ', $parts);
    }

    /**
     * Entfernt die GOV-Verknüpfung. meta_data bleibt erhalten, gov_id = NULL.
     */
    public function unlink(Tree $tree, int $placeId): void
    {
        DB::table('ortsregister_place_meta')
            ->where('tree_id',  '=', $tree->id())
            ->where('place_id', '=', $placeId)
            ->update(['gov_id' => null]);
        $this->cache?->flush();
    }

    /**
     * @param array<string, mixed> $metaPatch
     */
    private function upsertMeta(Tree $tree, int $placeId, string $govId, array $metaPatch): void
    {
        $existing = DB::table('ortsregister_place_meta')
            ->where('tree_id',  '=', $tree->id())
            ->where('place_id', '=', $placeId)
            ->select(['meta_data'])
            ->first();

        $existingMeta = [];
        if ($existing !== null && $existing->meta_data !== null && $existing->meta_data !== '') {
            try {
                $decoded = json_decode((string) $existing->meta_data, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $existingMeta = $decoded;
                }
            } catch (\JsonException) {
                // Bleibt leer wenn JSON kaputt
            }
        }
        $merged = array_replace_recursive($existingMeta, $metaPatch);
        $jsonOut = json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if ($existing === null) {
            DB::table('ortsregister_place_meta')->insert([
                'tree_id'   => $tree->id(),
                'place_id'  => $placeId,
                'gov_id'    => $govId,
                'meta_data' => $jsonOut,
            ]);
        } else {
            DB::table('ortsregister_place_meta')
                ->where('tree_id',  '=', $tree->id())
                ->where('place_id', '=', $placeId)
                ->update([
                    'gov_id'    => $govId,
                    'meta_data' => $jsonOut,
                ]);
        }
    }
}
