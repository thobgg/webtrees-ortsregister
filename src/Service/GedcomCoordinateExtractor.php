<?php

declare(strict_types=1);

namespace Ortsregister\Service;

/**
 * Extrahiert PLAC-Koordinaten (MAP/LATI/LONG) aus einem GEDCOM-Record-String.
 *
 * Viele Genealogie-Programme (Ahnenblatt, Gramps, FTM, MyHeritage) exportieren
 * Koordinaten als PLAC-Subtags. webtrees ignoriert sie bei der Anzeige und
 * nutzt nur die separate `place_location`-Tabelle. Dieser Extractor parst
 * die Subtags, der CoordinateImportService schreibt sie in place_location.
 *
 * Isoliert testbar — nur String-Operationen, keine DB/Tree-Abhängigkeit.
 */
class GedcomCoordinateExtractor
{
    /**
     * Findet alle PLAC-Blöcke mit MAP/LATI/LONG und gibt
     * [placValue, latitude, longitude] zurück.
     *
     * @return list<array{0: string, 1: float, 2: float}>
     */
    public function extract(string $gedcom): array
    {
        $lines = preg_split('/\R/', $gedcom) ?: [];
        $n     = count($lines);
        $out   = [];

        for ($i = 0; $i < $n; $i++) {
            if (preg_match('/^(\d+)\s+PLAC\s?(.*)$/u', $lines[$i], $m) !== 1) {
                continue;
            }
            $placLevel = (int) $m[1];
            $placValue = (string) $m[2];

            $lat = null;
            $lng = null;

            // Subtree nach MAP / LATI / LONG durchsuchen
            $j = $i + 1;
            while ($j < $n) {
                $lineLevel = $this->lineLevel($lines[$j]);
                if ($lineLevel <= $placLevel) {
                    break;
                }
                if (preg_match('/^\d+\s+MAP\b/u', $lines[$j]) === 1) {
                    $mapLevel = $lineLevel;
                    // MAP-Subtree-Inhalte
                    $k = $j + 1;
                    while ($k < $n && $this->lineLevel($lines[$k]) > $mapLevel) {
                        if (preg_match('/^\d+\s+LATI\s+(\S+)/u', $lines[$k], $lm) === 1) {
                            $lat = $this->parseGeo((string) $lm[1]);
                        } elseif (preg_match('/^\d+\s+LONG\s+(\S+)/u', $lines[$k], $lo) === 1) {
                            $lng = $this->parseGeo((string) $lo[1]);
                        }
                        $k++;
                    }
                    $j = $k;
                    continue;
                }
                $j++;
            }

            if ($lat !== null && $lng !== null && $placValue !== '') {
                $out[] = [$placValue, $lat, $lng];
            }
        }

        return $out;
    }

    /**
     * Parst GEDCOM-Geo-Strings: 'N48.6', 'E9.4', 'S-12.3' oder nackte Floats.
     * Süd/West → negativ.
     */
    private function parseGeo(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $sign = 1.0;
        $first = $s[0];
        if ($first === 'N' || $first === 'n' || $first === 'E' || $first === 'e') {
            $s = substr($s, 1);
        } elseif ($first === 'S' || $first === 's' || $first === 'W' || $first === 'w') {
            $sign = -1.0;
            $s    = substr($s, 1);
        }
        if (!is_numeric($s)) {
            return null;
        }
        return $sign * (float) $s;
    }

    private function lineLevel(string $line): int
    {
        if (preg_match('/^(\d+)/', $line, $m) === 1) {
            return (int) $m[1];
        }
        return -1;
    }
}
