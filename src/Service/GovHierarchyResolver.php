<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\GovObject;

/**
 * Löst die GOV-Hierarchie eines Place rekursiv über `partOfIds` auf.
 *
 * Strategie:
 *   - Pro Stufe nehmen wir den ERSTEN partOf-Eintrag.
 *     GOV liefert oft mehrere parallele Zugehörigkeiten (Zeitperioden).
 *     Zeitauflösung ist Sache einer späteren Phase.
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

    /**
     * Liefert die part-of-Kette von Blatt bis Wurzel.
     * Index 0 = Start-Objekt, danach jeweils Eltern.
     *
     * @return list<GovObject>
     */
    public function resolve(string $govId, int $maxDepth = self::MAX_DEPTH_DEFAULT): array
    {
        return array_map(static fn(array $step) => $step['obj'], $this->resolveWithEdges($govId, $maxDepth));
    }

    /**
     * Wie resolve(), aber liefert pro Stufe zusätzlich die Zeitspanne der
     * part-of-Beziehung VON DER VORHERIGEN STUFE ZU DIESER. Für Index 0
     * (Start-Objekt) sind begin/end null.
     *
     * @return list<array{obj: GovObject, begin: string|null, end: string|null}>
     */
    public function resolveWithEdges(string $govId, int $maxDepth = self::MAX_DEPTH_DEFAULT): array
    {
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

            $nextId       = $obj->partOfIds[0] ?? '';
            $meta         = $obj->partOfMeta[$nextId] ?? null;
            $pendingBegin = $meta['begin'] ?? null;
            $pendingEnd   = $meta['end']   ?? null;
            $currentId    = $nextId;
            $steps++;
        }
        return $chain;
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
