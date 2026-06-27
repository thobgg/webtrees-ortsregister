<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;

/**
 * Zählt Ereignisse pro Ort, aufgeschlüsselt nach GEDCOM-Tag.
 *
 * Hintergrund: `placelinks` enthält einen Link pro Record-Ort-Paar, aber
 * nicht das Event-Tag. Den Tag lesen wir aus dem `i_gedcom`/`f_gedcom`-Blob.
 * Ein Record kann mehrere Events am selben Ort haben (z.B. BIRT + DEAT).
 *
 * Wir matchen Events deren PLAC-Wert mit dem Blatt-Ortsnamen beginnt
 * (Komma-Hierarchie: erstes Segment).
 */
class PlaceEventCounter
{
    /** Genealogisch primäre Events — diese bekommen eigene Statistik-Karten. */
    private const PRIMARY_EVENT_TAGS = ['BIRT', 'MARR', 'DEAT'];

    /**
     * @return array{BIRT:int, MARR:int, DEAT:int, OTHER:int, TOTAL:int}
     */
    public function countFor(Tree $tree, int $placeId, string $placeLeafName): array
    {
        $counts = ['BIRT' => 0, 'MARR' => 0, 'DEAT' => 0, 'OTHER' => 0, 'TOTAL' => 0];

        $individualGedcoms = DB::table('placelinks AS pl')
            ->join('individuals AS i', function ($join) use ($tree) {
                $join->on('i.i_id',   '=', 'pl.pl_gid')
                     ->where('i.i_file', '=', $tree->id());
            })
            ->where('pl.pl_p_id', '=', $placeId)
            ->where('pl.pl_file', '=', $tree->id())
            ->select(['i.i_gedcom'])
            ->pluck('i_gedcom');

        foreach ($individualGedcoms as $gedcom) {
            foreach ($this->extractEventTags((string) $gedcom, $placeLeafName) as $tag) {
                $this->bucket($counts, $tag);
            }
        }

        $familyGedcoms = DB::table('placelinks AS pl')
            ->join('families AS f', function ($join) use ($tree) {
                $join->on('f.f_id',   '=', 'pl.pl_gid')
                     ->where('f.f_file', '=', $tree->id());
            })
            ->where('pl.pl_p_id', '=', $placeId)
            ->where('pl.pl_file', '=', $tree->id())
            ->select(['f.f_gedcom'])
            ->pluck('f_gedcom');

        foreach ($familyGedcoms as $gedcom) {
            foreach ($this->extractEventTags((string) $gedcom, $placeLeafName) as $tag) {
                $this->bucket($counts, $tag);
            }
        }

        return $counts;
    }

    /**
     * Mini-Parser: findet Level-1-Events deren Level-2-PLAC mit dem
     * Blatt-Ortsnamen beginnt (PLAC „Haberschlacht, Brackenheim, …" matcht
     * Blatt „Haberschlacht").
     *
     * @return list<string>
     */
    private function extractEventTags(string $gedcom, string $placeLeafName): array
    {
        $tags         = [];
        $currentTag   = null;
        $currentLevel = -1;

        foreach (preg_split('/\r?\n/', $gedcom) ?: [] as $line) {
            if (preg_match('/^(\d+)\s+(\S+)(?:\s+(.*))?$/', $line, $m) !== 1) {
                continue;
            }
            $level = (int) $m[1];
            $tag   = $m[2];
            $value = $m[3] ?? '';

            if ($level === 1) {
                // Neues Event-Top-Tag (BIRT/MARR/DEAT/…), Pointer-Lines (z.B. "1 FAMS @F1@") ignorieren wir,
                // weil deren PLAC-Subtree nicht hier liegt.
                $currentTag   = ($value === '' || !str_starts_with($value, '@')) ? $tag : null;
                $currentLevel = 1;
                continue;
            }

            if ($currentTag !== null && $level === 2 && $tag === 'PLAC') {
                $leaf = trim(explode(',', $value, 2)[0]);
                if ($leaf === $placeLeafName) {
                    $tags[] = $currentTag;
                }
                // Pro Event nur einmal zählen — auch wenn theoretisch
                // mehrere PLAC-Geschwister existieren würden.
                $currentTag = null;
            }
        }
        return $tags;
    }

    /**
     * @param array{BIRT:int, MARR:int, DEAT:int, OTHER:int, TOTAL:int} $counts
     */
    private function bucket(array &$counts, string $tag): void
    {
        $counts['TOTAL']++;
        if (in_array($tag, self::PRIMARY_EVENT_TAGS, true)) {
            $counts[$tag]++;
        } else {
            $counts['OTHER']++;
        }
    }
}
