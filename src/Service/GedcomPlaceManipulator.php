<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\SubtagConflict;

/**
 * Manipuliert PLAC-Subtrees innerhalb eines GEDCOM-Record-Strings.
 *
 * Architektur-Konsens „GEDCOM persistent" (Modell A):
 * Der manipulierte GEDCOM-String wird via GedcomRecord::updateRecord()
 * zurückgeschrieben. webtrees-Core re-derived alle Index-Tabellen
 * (placelinks, places, ...) daraus automatisch.
 *
 * Diese Klasse kennt NUR Strings — keine DB, kein Tree, kein Record.
 * Damit ist sie isoliert testbar.
 *
 * Subtag-Strategie beim Merge:
 * - Quell- und Ziel-PLAC haben identischen Subtag-Wert  → silent OK
 * - Subtag nur in einem PLAC vorhanden                  → opake Übernahme
 * - Subtag in beiden mit verschiedenem Wert             → SubtagConflict
 *   → User-Resolution: source / target / drop (Entscheidung 1A: Default target)
 * - Mehrfach-Vorkommen desselben Subtag-Namens          → Vereinigung
 *   (Entscheidung 3B: einfacher als Konflikt-Liste, deckt NOTE/SOUR ab)
 */
class GedcomPlaceManipulator
{
    /**
     * Ersetzt alle PLAC-Vorkommen mit Wert $sourceValue durch $targetValue
     * unter Berücksichtigung der Subtag-Resolutionen.
     *
     * @param string                       $gedcom         Vollständiger Record-GEDCOM-String
     * @param string                       $sourceValue    PLAC-Wert der Quelle
     * @param string                       $targetValue    PLAC-Wert des Ziels
     * @param array<string, list<string>>  $targetSubtags  Subtags des Ziel-PLAC (Tag → Werte-Liste)
     * @param array<string, string>        $resolutions    Tag → Resolution (RESOLUTION_*)
     *
     * @return string Neuer GEDCOM-String
     */
    public function replacePlacBlock(
        string $gedcom,
        string $sourceValue,
        string $targetValue,
        array  $targetSubtags = [],
        array  $resolutions   = [],
    ): string {
        $lines  = preg_split('/\R/', $gedcom) ?: [];
        $result = [];
        $i      = 0;
        $n      = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            if (preg_match('/^(\d+)\s+PLAC\s?(.*)$/u', $line, $m) === 1
                && (string) $m[2] === $sourceValue) {
                $anchorLevel = (int) $m[1];

                // Subtree einsammeln
                $subtreeLines = [];
                $j = $i + 1;
                while ($j < $n && $this->lineLevel($lines[$j]) > $anchorLevel) {
                    $subtreeLines[] = $lines[$j];
                    $j++;
                }

                // Subtree neu aufbauen (Resolutions anwenden, opake Subtags vereinigen)
                $newSubtree = $this->mergeSubtrees(
                    $subtreeLines,
                    $targetSubtags,
                    $resolutions,
                    $anchorLevel,
                );

                $result[] = sprintf('%d PLAC %s', $anchorLevel, $targetValue);
                foreach ($newSubtree as $sub) {
                    $result[] = $sub;
                }

                $i = $j;
                continue;
            }

            $result[] = $line;
            $i++;
        }

        return implode("\n", $result);
    }

    /**
     * Liefert die direkten (rel_level=1) Subtags eines PLAC-Subtrees als
     * Tag → list<value>. Mehrfach-Vorkommen werden als Liste gesammelt.
     *
     * @param string $gedcom        kompletter Record-String
     * @param string $placValue     PLAC-Wert dessen Subtags geliefert werden
     *
     * @return array<string, list<string>>
     */
    public function extractDirectSubtags(string $gedcom, string $placValue): array
    {
        $lines = preg_split('/\R/', $gedcom) ?: [];
        $n     = count($lines);
        $result = [];

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            if (preg_match('/^(\d+)\s+PLAC\s?(.*)$/u', $line, $m) !== 1
                || (string) $m[2] !== $placValue) {
                continue;
            }
            $anchorLevel = (int) $m[1];

            // Direkte Kinder einsammeln (level === anchorLevel + 1)
            $j = $i + 1;
            while ($j < $n && $this->lineLevel($lines[$j]) > $anchorLevel) {
                $childLevel = $this->lineLevel($lines[$j]);
                if ($childLevel === $anchorLevel + 1
                    && preg_match('/^\d+\s+(\S+)\s?(.*)$/u', $lines[$j], $cm) === 1) {
                    $result[$cm[1]][] = (string) $cm[2];
                }
                $j++;
            }
        }

        return $result;
    }

    /**
     * Liefert alle direkten Tag-Namen mit Wert (kein Sub-Subtag-Verlust:
     * Sub-Subtags werden mitgenommen, aber im Wert-Vergleich gezählt der
     * normalisierte Sub-Subtree als ein Wert pro Tag-Name).
     *
     * @return array<string, list<string>>  Tag → Liste von „Werten" (inkl. Sub-Subtags als Mehrzeiler)
     */
    public function extractDirectSubtagsWithSubtree(string $gedcom, string $placValue): array
    {
        $lines = preg_split('/\R/', $gedcom) ?: [];
        $n     = count($lines);
        $result = [];

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            if (preg_match('/^(\d+)\s+PLAC\s?(.*)$/u', $line, $m) !== 1
                || (string) $m[2] !== $placValue) {
                continue;
            }
            $anchorLevel = (int) $m[1];

            $j = $i + 1;
            while ($j < $n && $this->lineLevel($lines[$j]) > $anchorLevel) {
                $childLevel = $this->lineLevel($lines[$j]);
                if ($childLevel === $anchorLevel + 1
                    && preg_match('/^\d+\s+(\S+)\s?(.*)$/u', $lines[$j], $cm) === 1) {
                    $tag = (string) $cm[1];
                    $value = (string) $cm[2];

                    // Sub-Sub-Lines einsammeln
                    $subValue = $value;
                    $k = $j + 1;
                    while ($k < $n && $this->lineLevel($lines[$k]) > $childLevel) {
                        $subValue .= "\n" . $lines[$k];
                        $k++;
                    }
                    $result[$tag][] = $subValue;
                    $j = $k;
                    continue;
                }
                $j++;
            }
        }

        return $result;
    }

    /**
     * Berechnet Subtag-Konflikte zwischen Quell- und Ziel-Subtags.
     *
     * @param array<string, list<string>> $sourceSubtags
     * @param array<string, list<string>> $targetSubtags
     *
     * @return list<SubtagConflict>
     */
    public function detectConflicts(array $sourceSubtags, array $targetSubtags): array
    {
        $conflicts = [];
        foreach ($sourceSubtags as $tag => $srcValues) {
            if (!array_key_exists($tag, $targetSubtags)) {
                continue;
            }
            // Bei Mehrfach-Vorkommen: jedes Werte-Paar prüfen wäre Overkill.
            // Wir vergleichen die normalisierten Listen — wenn sie identisch
            // sind (auch in Reihenfolge), kein Konflikt; sonst Konflikt-Eintrag
            // mit den ersten abweichenden Werten als Repräsentanten.
            $srcSorted = $srcValues;
            $dstSorted = $targetSubtags[$tag];
            sort($srcSorted);
            sort($dstSorted);
            if ($srcSorted === $dstSorted) {
                continue;
            }
            $conflicts[] = new SubtagConflict(
                tag:         $tag,
                sourceValue: implode(' | ', $srcValues),
                targetValue: implode(' | ', $targetSubtags[$tag]),
            );
        }
        return $conflicts;
    }

    // ---------------------------------------------------------------
    // Intern
    // ---------------------------------------------------------------

    private function lineLevel(string $line): int
    {
        if (preg_match('/^(\d+)/', $line, $m) === 1) {
            return (int) $m[1];
        }
        return -1;
    }

    /**
     * Baut den neuen Subtree für den ersetzten PLAC.
     *
     * Strategie:
     * - Konflikt-Tags (in $resolutions enthalten) gemäß Resolution
     * - Nicht-Konflikt-Tags der Quelle werden alle übernommen (Vereinigung)
     * - Subtags des Ziels, die NICHT in der Quelle vorkommen, werden ebenfalls übernommen
     * - Subtags die in beiden identisch sind, erscheinen einmal (Ziel-Variante)
     *
     * @param list<string>                $sourceSubtreeLines  rohe GEDCOM-Zeilen der Quell-Subtree
     * @param array<string, list<string>> $targetSubtagsRaw    Ziel-Tag → list<value>
     * @param array<string, string>       $resolutions         Tag → 'source'|'target'|'drop'
     *
     * @return list<string>  Neue GEDCOM-Zeilen (Subtree, ohne PLAC-Header)
     */
    private function mergeSubtrees(
        array $sourceSubtreeLines,
        array $targetSubtagsRaw,
        array $resolutions,
        int   $anchorLevel,
    ): array {
        $output = [];

        // Schritt 1: Quell-Subtags gruppieren (Tag → list<Subtree-Lines>)
        $sourceGroups = $this->groupByDirectTag($sourceSubtreeLines, $anchorLevel);

        // Schritt 2: Pro Quell-Tag entscheiden
        $usedTargetTags = [];
        foreach ($sourceGroups as $tag => $blocks) {
            $resolution = $resolutions[$tag] ?? null;

            if ($resolution === SubtagConflict::RESOLUTION_DROP) {
                continue;
            }

            if ($resolution === SubtagConflict::RESOLUTION_SOURCE) {
                foreach ($blocks as $block) {
                    foreach ($block as $line) {
                        $output[] = $line;
                    }
                }
                $usedTargetTags[$tag] = true;
                continue;
            }

            if ($resolution === SubtagConflict::RESOLUTION_TARGET
                && isset($targetSubtagsRaw[$tag])) {
                foreach ($targetSubtagsRaw[$tag] as $value) {
                    $output[] = sprintf('%d %s%s', $anchorLevel + 1, $tag, $value === '' ? '' : ' ' . $value);
                }
                $usedTargetTags[$tag] = true;
                continue;
            }

            // Kein expliziter Konflikt → opake Übernahme aus Quelle
            foreach ($blocks as $block) {
                foreach ($block as $line) {
                    $output[] = $line;
                }
            }
            $usedTargetTags[$tag] = true;
        }

        // Schritt 3: Ziel-Subtags die in der Quelle FEHLEN, ergänzen
        foreach ($targetSubtagsRaw as $tag => $values) {
            if (isset($usedTargetTags[$tag])) {
                continue;
            }
            foreach ($values as $value) {
                $output[] = sprintf('%d %s%s', $anchorLevel + 1, $tag, $value === '' ? '' : ' ' . $value);
            }
        }

        return $output;
    }

    /**
     * @param list<string> $lines
     * @return array<string, list<list<string>>>  Tag → Liste von Blöcken (jeder Block = Liste von Zeilen)
     */
    private function groupByDirectTag(array $lines, int $anchorLevel): array
    {
        $groups = [];
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            $level = $this->lineLevel($lines[$i]);
            if ($level !== $anchorLevel + 1) {
                $i++;
                continue;
            }
            if (preg_match('/^\d+\s+(\S+)/', $lines[$i], $m) !== 1) {
                $i++;
                continue;
            }
            $tag = (string) $m[1];
            $block = [$lines[$i]];
            $j = $i + 1;
            while ($j < $n && $this->lineLevel($lines[$j]) > $level) {
                $block[] = $lines[$j];
                $j++;
            }
            $groups[$tag][] = $block;
            $i = $j;
        }
        return $groups;
    }
}
