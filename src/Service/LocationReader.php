<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\LocationIdentity;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Location;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

/**
 * Liest vorhandene GEDCOM-L `_LOC`-Records (Identitäts-Schicht) — REIN LESEND.
 *
 * `_LOC` ist nativ im webtrees-Core (`Fisharebest\Webtrees\Location`,
 * `Registry::locationFactory()`), keine Vesta-Abhängigkeit. Dieser Reader
 * erkennt einen `_LOC`-Record und zieht die verifizierte Grammatik heraus
 * (NAME/TYPE/MAP/_GOV/_LOC-Hierarchie). Er SCHREIBT nichts.
 *
 * Der `parse()`-Kern ist isoliert testbar (nur String-Operationen), der
 * `make()`-Pfad löst einen Xref über die Core-Factory auf.
 *
 * Grammatik-Quelle: Memo „reference_loc_grammar_native".
 */
final class LocationReader
{
    /**
     * Löst einen `_LOC`-Xref (z.B. aus einem `PLAC`-`_LOC @L1@`-Zeiger) über die
     * Core-Factory auf. null, wenn kein `_LOC`-Record mit diesem Xref existiert.
     */
    public function make(Tree $tree, string $xref): ?LocationIdentity
    {
        $xref = $this->normaliseXref($xref);
        if ($xref === '') {
            return null;
        }
        $location = Registry::locationFactory()->make($xref, $tree);
        if ($location === null) {
            return null;
        }
        return $this->parse($xref, $location->gedcom());
    }

    /**
     * Findet alle `_LOC`-Records im Baum, deren `1 NAME` (irgendeine Variante)
     * dem Blattnamen des Orts entspricht (case-insensitiv). REIN LESEND.
     *
     * Heuristik, keine harte Verknüpfung: mehrere Orte können denselben
     * Blattnamen tragen. Die Anzeige muss das als „passender Name" kennzeichnen,
     * nicht als bewiesene Zuordnung.
     *
     * @return list<LocationIdentity>
     */
    public function forPlaceName(Tree $tree, string $leafName): array
    {
        $leafName = trim($leafName);
        if ($leafName === '') {
            return [];
        }

        $rows = DB::table('other')
            ->where('o_file', '=', $tree->id())
            ->where('o_type', '=', Location::RECORD_TYPE)
            ->select(['o_id', 'o_gedcom'])
            ->get();

        $needle = $this->foldName($leafName);
        $out    = [];
        foreach ($rows as $row) {
            $id = $this->parse((string) $row->o_id, (string) $row->o_gedcom);
            foreach ($id->names as $name) {
                if ($this->foldName($name) === $needle) {
                    $out[] = $id;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Parst die verifizierte `_LOC`-Grammatik aus rohem Record-GEDCOM.
     * Unbekannte/nicht-verifizierte Tags werden ignoriert (keine Semantik-Raterei).
     */
    public function parse(string $xref, string $gedcom): LocationIdentity
    {
        $lines = preg_split('/\R/', $gedcom) ?: [];

        $names       = [];
        $govId       = null;
        $type        = null;
        $lat         = null;
        $lon         = null;
        $parentXrefs = [];

        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            if (preg_match('/^(\d+)\s+(\S+)(?:\s(.*))?$/u', $line, $m) !== 1) {
                continue;
            }
            $level = (int) $m[1];
            $tag   = $m[2];
            $value = isset($m[3]) ? trim($m[3]) : '';

            if ($level !== 1) {
                continue;
            }

            switch ($tag) {
                case 'NAME':
                    if ($value !== '') {
                        $names[] = $value;
                    }
                    break;
                case 'TYPE':
                    if ($type === null && $value !== '') {
                        $type = $value;
                    }
                    break;
                case '_GOV':
                    if ($govId === null && $value !== '') {
                        $govId = $value;
                    }
                    break;
                case '_LOC':
                    $ref = $this->normaliseXref($value);
                    if ($ref !== '') {
                        $parentXrefs[] = $ref;
                    }
                    break;
                case 'MAP':
                    // LATI/LONG sind Level-2-Kinder direkt unter MAP.
                    for ($j = $i + 1; $j < $n && $this->lineLevel($lines[$j]) > 1; $j++) {
                        if (preg_match('/^\d+\s+LATI\s+(\S+)/u', $lines[$j], $lm) === 1) {
                            $lat = $this->parseGeo((string) $lm[1]);
                        } elseif (preg_match('/^\d+\s+LONG\s+(\S+)/u', $lines[$j], $lo) === 1) {
                            $lon = $this->parseGeo((string) $lo[1]);
                        }
                    }
                    break;
            }
        }

        // Koordinaten nur als Paar gültig.
        if ($lat === null || $lon === null) {
            $lat = null;
            $lon = null;
        }

        return new LocationIdentity(
            xref:        $this->normaliseXref($xref),
            names:       array_values($names),
            govId:       $govId,
            latitude:    $lat,
            longitude:   $lon,
            type:        $type,
            parentXrefs: array_values(array_unique($parentXrefs)),
        );
    }

    /**
     * `@L1@` → `L1`; toleriert bereits nackte Xrefs. Leerer/ungültiger Input → ''.
     */
    private function normaliseXref(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^@([^@]+)@$/', $raw, $m) === 1) {
            return $m[1];
        }
        // Nur „einfache" Xref-Zeichen zulassen — sonst leer (kein Zeiger).
        return preg_match('/^[A-Za-z0-9_]+$/', $raw) === 1 ? $raw : '';
    }

    /** Normalisiert einen Ortsnamen für den case-/whitespace-toleranten Vergleich. */
    private function foldName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    private function parseGeo(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $sign  = 1.0;
        $first = $s[0];
        if ($first === 'N' || $first === 'n' || $first === 'E' || $first === 'e') {
            $s = substr($s, 1);
        } elseif ($first === 'S' || $first === 's' || $first === 'W' || $first === 'w') {
            $sign = -1.0;
            $s    = substr($s, 1);
        }
        return is_numeric($s) ? $sign * (float) $s : null;
    }

    private function lineLevel(string $line): int
    {
        return preg_match('/^(\d+)/', $line, $m) === 1 ? (int) $m[1] : -1;
    }
}
