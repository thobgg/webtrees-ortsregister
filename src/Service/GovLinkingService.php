<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\GovObject;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;
use RuntimeException;

/**
 * Verknüpft webtrees-Places mit GOV-IDs und cached die GOV-Antwort
 * in ortsregister_place_meta (gov_id-Spalte + meta_data-JSON).
 */
class GovLinkingService
{
    public function __construct(
        private readonly GovApiClient $govClient,
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
        return $obj;
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
