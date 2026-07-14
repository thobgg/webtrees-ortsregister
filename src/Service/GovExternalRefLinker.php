<?php

declare(strict_types=1);

namespace Ortsregister\Service;

/**
 * Löst externe GOV-Kennungen (`externalReference`, Format `präfix:id`) in klickbare
 * Links auf — z.B. `GND:4015575-4` → https://d-nb.info/gnd/4015575-4.
 *
 * REIN, testbar (nur String-Arbeit). Doktrin „keine Semantik raten"
 * ([[feedback_external_system_claims]]): es werden NUR Präfixe verlinkt, deren
 * URL-Muster empirisch verifiziert wurde (gültige ID → HTTP 200, Falschform → 404,
 * 2026-07-14).
 *
 * KURATIERT statt vollständig: GOV kann sehr viele externe Systeme führen (VIAF, NUTS,
 * regionale DBs …). Für eine übersichtliche Ortsseite wird bewusst nur eine kleine,
 * genealogisch sinnvolle Whitelist verlinkt; alles andere (unbekannte Präfixe, tote
 * Systeme wie opengeodb, volle URLs wie OSM — Karte gibt es schon) wird VERWORFEN,
 * nicht als Klartext gezeigt. Lieber weniger und sauber als eine Kürzel-Wüste.
 *
 * Quelle der Muster: GenWiki „GOV/Externe_Kennungen" (Doku bot-geschützt), daher
 * gegen die kanonischen Resolver verifiziert (DNB-Permalink, GeoNames, Wikidata,
 * LEO-BW-Detailseite).
 */
final class GovExternalRefLinker
{
    /**
     * präfix (kleingeschrieben) => [Anzeige-Label, URL-Vorlage mit {id}].
     *
     * @var array<string, array{label: string, url: string}>
     */
    private const KNOWN = [
        'gnd'      => ['label' => 'GND',      'url' => 'https://d-nb.info/gnd/{id}'],
        'geonames' => ['label' => 'GeoNames', 'url' => 'https://www.geonames.org/{id}'],
        'wikidata' => ['label' => 'Wikidata', 'url' => 'https://www.wikidata.org/wiki/{id}'],
        'leobw'    => ['label' => 'LEO-BW',   'url' => 'https://www.leo-bw.de/detail/-/Detail/details/ORT/{id}'],
    ];

    /**
     * Nur die kuratierten, verifizierten Systeme — in der Reihenfolge der Eingabe,
     * Duplikate (gleicher Präfix+ID) entfernt. Alles andere fällt weg.
     *
     * @param list<string> $refs
     * @return list<array{label: string, id: string, url: string}>
     */
    public function resolveAll(array $refs): array
    {
        $out  = [];
        $seen = [];
        foreach ($refs as $ref) {
            $r = $this->resolve($ref);
            if ($r === null) {
                continue;
            }
            $key = $r['label'] . '|' . $r['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $r;
        }
        return $out;
    }

    /**
     * @return array{label: string, id: string, url: string}|null
     *         null = nicht kuratiert/nicht verlinkbar → wird NICHT angezeigt
     */
    public function resolve(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        $pos = strpos($ref, ':');
        if ($pos === false) {
            return null;
        }

        $prefix = strtolower(substr($ref, 0, $pos));
        $id     = substr($ref, $pos + 1);
        if ($id === '') {
            return null;
        }

        $known = self::KNOWN[$prefix] ?? null;
        if ($known === null) {
            // Nicht kuratiert (opengeodb, VIAF, volle URLs …) → bewusst nicht anzeigen.
            return null;
        }

        return [
            'label' => $known['label'],
            'id'    => $id,
            'url'   => str_replace('{id}', rawurlencode($id), $known['url']),
        ];
    }
}
