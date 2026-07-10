<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\OrtDto;

/**
 * Varianten-Gruppen für die Orte-Liste (Issue #11): erkennt, welche Listen-Zeilen
 * wahrscheinlich EIN realer Ort sind — webtrees legt pro Schreibweise der Elternkette
 * (DEU/Germany/Deutschland …) einen eigenen Orts-Datensatz an, die Liste zeigte diese
 * Fragmente bisher als lose Einzelzeilen.
 *
 * Zwei Signale, bewusst getrennt (Konzept: Modul zeigt, Nutzer entscheidet):
 *  - GOV-Gruppe (autoritativ): gleiche GOV-Kennung = sicher derselbe reale Ort.
 *  - Namens-Gruppe (Heuristik): gleiches Blatt = Kandidaten. Ob Schreibrauschen (→ Merge),
 *    Zeit-Variante (→ GOV-Verknüpfung) oder echte Namensvetter (Achse A → getrennt lassen),
 *    entscheidet der Nutzer auf der Ortsseite.
 *
 * REIN (keine DB) — zählt über die bereits geladene DTO-Liste.
 */
final class VariantenGruppierung
{
    /**
     * @param list<OrtDto> $orte  ungefilterte Liste (Filter würde Gruppen zerreißen)
     * @return array{name: array<string,int>, gov: array<string,int>}
     *         name: casefold(Blattname) → Anzahl Einträge · gov: GOV-Kennung → Anzahl Einträge
     */
    public static function zaehle(array $orte): array
    {
        $byName = [];
        $byGov  = [];
        foreach ($orte as $ort) {
            $key = self::nameKey($ort->name);
            if ($key !== '') {
                $byName[$key] = ($byName[$key] ?? 0) + 1;
            }
            if ($ort->hatGov()) {
                $byGov[(string) $ort->govId] = ($byGov[(string) $ort->govId] ?? 0) + 1;
            }
        }
        return ['name' => $byName, 'gov' => $byGov];
    }

    /** Gruppen-Schlüssel für Blattnamen: getrimmt + casefold (fängt „oberurbach" vs „Oberurbach"). */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
