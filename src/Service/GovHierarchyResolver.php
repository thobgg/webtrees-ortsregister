<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\GovObject;

/**
 * Löst die GOV-Hierarchie eines Place rekursiv über `partOfIds` auf.
 *
 * Strategie:
 *   - GOV liefert pro Stufe mehrere partOf-Einträge mit Zeitspannen (beginYear/
 *     endYear). Wir wählen zeit-bewusst:
 *       MODE_CURRENT    = der partOf-Eintrag OHNE Ende (läuft bis heute) → die
 *                         heutige Zugehörigkeit (z.B. Oberurbach → Urbach ab 1970).
 *                         GOVs eigenes `located-in` ist oft leer, daher NICHT primär.
 *       MODE_HISTORICAL = der älteste beendete partOf-Eintrag → die ursprüngliche
 *                         Hierarchie, mit Zeitspanne.
 *     (Die volle Epochen-Zeitleiste — ALLE partOf-Kanten nebeneinander — ist eine
 *     spätere Stufe.)
 *   - Lookups gehen alle über GovApiClient → Cache (7d TTL) macht
 *     wiederholte Anzeigen praktisch kostenlos.
 *   - Cycle-Detection via visited-Set (GOV hat real Self-References).
 *   - Hard Cap MAX_DEPTH gegen pathologische Ketten.
 */
class GovHierarchyResolver
{
    public const MAX_DEPTH_DEFAULT = 10;

    public function __construct(
        private readonly GovApiClient $client,
    ) {}

    public const MODE_HISTORICAL = 'historical';
    public const MODE_CURRENT    = 'current';

    /**
     * Liefert die Hierarchie-Kette von Blatt bis Wurzel.
     * Index 0 = Start-Objekt, danach jeweils Eltern.
     *
     * @param string $mode self::MODE_HISTORICAL (partOfIds, mit Zeitspannen)
     *                     oder self::MODE_CURRENT (locatedInIds, ohne Zeit)
     * @return list<GovObject>
     */
    public function resolve(
        string $govId,
        string $mode = self::MODE_HISTORICAL,
        int $maxDepth = self::MAX_DEPTH_DEFAULT,
    ): array {
        return array_map(static fn(array $step) => $step['obj'], $this->resolveWithEdges($govId, $mode, $maxDepth));
    }

    /**
     * Wie resolve(), aber liefert pro Stufe zusätzlich die Zeitspanne der
     * Beziehung VON DER VORHERIGEN STUFE ZU DIESER (nur im MODE_HISTORICAL
     * sinnvoll — locatedIn-Beziehungen haben keine Zeit).
     *
     * @return list<array{obj: GovObject, begin: string|null, end: string|null}>
     */
    public function resolveWithEdges(
        string $govId,
        string $mode = self::MODE_HISTORICAL,
        int $maxDepth = self::MAX_DEPTH_DEFAULT,
    ): array {
        $chain         = [];
        $visited       = [];
        $currentId     = $govId;
        $steps         = 0;
        $pendingBegin  = null;
        $pendingEnd    = null;

        while ($currentId !== '' && $steps < $maxDepth) {
            if (isset($visited[$currentId])) {
                break;
            }
            $visited[$currentId] = true;

            $obj = $this->client->getObject($currentId);
            if ($obj === null) {
                break;
            }
            $chain[] = ['obj' => $obj, 'begin' => $pendingBegin, 'end' => $pendingEnd];

            // Modus entscheidet welche Eltern-Beziehung gewählt wird — zeit-bewusst.
            $nextId       = $mode === self::MODE_CURRENT
                ? $this->currentParentId($obj)
                : $this->historicalParentId($obj);
            $meta         = $obj->partOfMeta[$nextId] ?? null;
            $pendingBegin = $meta['begin'] ?? null;
            // Aktuelle Zugehörigkeit hat kein Ende (läuft bis heute).
            $pendingEnd   = $mode === self::MODE_CURRENT ? null : ($meta['end'] ?? null);

            $currentId    = $nextId;
            $steps++;
        }
        return $chain;
    }

    /**
     * Heutige Zugehörigkeit:
     *   1. explizites `located-in` (GOVs „aktuell räumlich"), falls vorhanden;
     *   2. sonst der partOf-Eintrag OHNE Ende (läuft bis heute) — real oft der
     *      einzige Weg, weil `located-in` bei vielen Objekten leer ist (verifiziert
     *      an Oberurbach: located-in leer, „ab 1970 Urbach" nur als offener partOf);
     *   3. Fallback: erster partOf.
     */
    private function currentParentId(GovObject $obj): string
    {
        if (($obj->locatedInIds[0] ?? '') !== '') {
            return $obj->locatedInIds[0];
        }
        $best      = '';
        $bestBegin = -1;
        foreach ($obj->partOfIds as $ref) {
            $meta = $obj->partOfMeta[$ref] ?? null;
            $end  = $meta['end'] ?? null;
            if ($end === null || $end === '') {
                $begin = (int) ($meta['begin'] ?? 0);
                if ($begin >= $bestBegin) {
                    $bestBegin = $begin;
                    $best      = $ref;
                }
            }
        }
        return $best !== '' ? $best : ($obj->partOfIds[0] ?? '');
    }

    /**
     * Ursprüngliche Zugehörigkeit = beendeter partOf-Eintrag (hat ein Ende) mit
     * dem frühesten Beginn. Fällt auf den ersten partOf zurück.
     */
    private function historicalParentId(GovObject $obj): string
    {
        $best      = '';
        $bestBegin = PHP_INT_MAX;
        foreach ($obj->partOfIds as $ref) {
            $meta = $obj->partOfMeta[$ref] ?? null;
            $end  = $meta['end'] ?? null;
            if ($end !== null && $end !== '') {
                $begin = (int) ($meta['begin'] ?? 0);
                if ($begin < $bestBegin) {
                    $bestBegin = $begin;
                    $best      = $ref;
                }
            }
        }
        return $best !== '' ? $best : ($obj->partOfIds[0] ?? '');
    }

    /**
     * Liefert den deutschen Namen falls vorhanden, sonst den primaryName.
     */
    public function germanNameOf(GovObject $obj): string
    {
        return $obj->namesByLang['deu']
            ?? $obj->namesByLang['de']
            ?? $obj->primaryName;
    }
}
