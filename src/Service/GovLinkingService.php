<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Dto\GovObject;
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

        // OrtDto ist gecacht (gov_id + Koordinaten) — nach dem Schreiben leeren,
        // sonst zeigen Ortsseite und _LOC-Vorschau bis zu 20 Min alte Daten.
        $this->cache?->flush();

        return $obj;
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
