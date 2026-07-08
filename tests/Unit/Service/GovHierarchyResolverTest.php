<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Dto\GovObject;
use Ortsregister\Service\GovApiClient;
use Ortsregister\Service\GovHierarchyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GovHierarchyResolver::class)]
final class GovHierarchyResolverTest extends TestCase
{
    public function testResolveWalksPartOfChainToRoot(): void
    {
        $resolver = new GovHierarchyResolver($this->stubClient([
            'object_leaf' => $this->makeObj('object_leaf', 'Weiler',          ['object_mid']),
            'object_mid'  => $this->makeObj('object_mid',  'Amt Kirchheim',   ['object_root']),
            'object_root' => $this->makeObj('object_root', 'Württemberg',     []),
        ]));

        $chain = $resolver->resolve('object_leaf');

        self::assertCount(3, $chain);
        self::assertSame('object_leaf', $chain[0]->govId);
        self::assertSame('object_mid',  $chain[1]->govId);
        self::assertSame('object_root', $chain[2]->govId);
    }

    public function testResolveStopsOnCycle(): void
    {
        $resolver = new GovHierarchyResolver($this->stubClient([
            'object_a' => $this->makeObj('object_a', 'A', ['object_b']),
            'object_b' => $this->makeObj('object_b', 'B', ['object_a']), // Zyklus
        ]));

        $chain = $resolver->resolve('object_a');

        self::assertCount(2, $chain);
        self::assertSame('object_a', $chain[0]->govId);
        self::assertSame('object_b', $chain[1]->govId);
    }

    public function testResolveRespectsMaxDepth(): void
    {
        $lookups = [];
        for ($i = 1; $i <= 20; $i++) {
            $next = $i < 20 ? ['object_' . ($i + 1)] : [];
            $lookups['object_' . $i] = $this->makeObj('object_' . $i, 'N' . $i, $next);
        }
        $resolver = new GovHierarchyResolver($this->stubClient($lookups));

        $chain = $resolver->resolve('object_1', maxDepth: 3);

        self::assertCount(3, $chain);
        self::assertSame('object_3', $chain[2]->govId);
    }

    public function testResolveStopsOnApiNullResponse(): void
    {
        $resolver = new GovHierarchyResolver($this->stubClient([
            'object_leaf' => $this->makeObj('object_leaf', 'X', ['object_missing']),
            // object_missing fehlt → Client liefert null
        ]));

        $chain = $resolver->resolve('object_leaf');

        self::assertCount(1, $chain);
        self::assertSame('object_leaf', $chain[0]->govId);
    }

    public function testResolveSingleNodeWithoutPartOf(): void
    {
        $resolver = new GovHierarchyResolver($this->stubClient([
            'object_root' => $this->makeObj('object_root', 'Welt', []),
        ]));

        $chain = $resolver->resolve('object_root');

        self::assertCount(1, $chain);
        self::assertSame('object_root', $chain[0]->govId);
    }

    public function testGermanNameOfPrefersDeu(): void
    {
        $resolver = new GovHierarchyResolver($this->stubClient([]));
        $obj      = new GovObject(
            govId:        'object_1',
            primaryName:  'Hamburg',
            namesByLang:  ['deu' => 'Hamburg-DE', 'eng' => 'Hamburg-EN'],
            typeIds:      [],
            latitude:     null,
            longitude:    null,
            partOfIds:    [],
            locatedInIds: [],
            externalUrls: [],
            rawJson:      [],
        );
        self::assertSame('Hamburg-DE', $resolver->germanNameOf($obj));
    }

    public function testGermanNameOfFallsBackToPrimary(): void
    {
        $resolver = new GovHierarchyResolver($this->stubClient([]));
        $obj      = new GovObject(
            govId:        'object_1',
            primaryName:  'Primary',
            namesByLang:  ['fra' => 'Hambourg'],
            typeIds:      [],
            latitude:     null,
            longitude:    null,
            partOfIds:    [],
            locatedInIds: [],
            externalUrls: [],
            rawJson:      [],
        );
        self::assertSame('Primary', $resolver->germanNameOf($obj));
    }

    /**
     * @param array<string, GovObject> $lookups  GOV-ID → DTO
     */
    private function stubClient(array $lookups): GovApiClient
    {
        return new class($lookups) extends GovApiClient {
            public function __construct(private readonly array $lookups)
            {
                // Parent-Constructor bewusst NICHT aufrufen — wir brauchen den Cache nicht.
            }

            public function getObject(string $govId): ?GovObject
            {
                return $this->lookups[$govId] ?? null;
            }
        };
    }

    public function testResolveWithEdgesCarriesPartOfTimeSpansForward(): void
    {
        $leaf   = new GovObject('object_leaf', 'Weiler', ['deu' => 'Weiler'], [], null, null,
            ['object_mid'], [], [], [], ['object_mid' => ['begin' => '1806', 'end' => '1813']]);
        $mid    = new GovObject('object_mid', 'Amt Kirchheim', ['deu' => 'Amt Kirchheim'], [], null, null,
            ['object_root'], [], [], [], ['object_root' => ['begin' => '1500', 'end' => '1806']]);
        $root   = new GovObject('object_root', 'Württemberg', ['deu' => 'Württemberg'], [], null, null,
            [], [], [], [], []);

        $resolver = new GovHierarchyResolver($this->stubClient([
            'object_leaf' => $leaf,
            'object_mid'  => $mid,
            'object_root' => $root,
        ]));

        $edges = $resolver->resolveWithEdges('object_leaf');

        self::assertCount(3, $edges);
        self::assertNull($edges[0]['begin']);
        self::assertNull($edges[0]['end']);
        self::assertSame('1806', $edges[1]['begin']);
        self::assertSame('1813', $edges[1]['end']);
        self::assertSame('1500', $edges[2]['begin']);
        self::assertSame('1806', $edges[2]['end']);
    }

    public function testResolveCurrentModeWalksLocatedInIdsNotPartOf(): void
    {
        // Leaf: partOf zeigt nach 'historical', locatedIn nach 'modern'
        $leaf = new GovObject(
            govId: 'object_leaf', primaryName: 'Weiler', namesByLang: ['deu' => 'Weiler'],
            typeIds: [], latitude: null, longitude: null,
            partOfIds: ['object_historical'], locatedInIds: ['object_modern'],
            externalUrls: [], rawJson: [], partOfMeta: [],
        );
        $historical = $this->makeObj('object_historical', 'Rheinbund', []);
        $modern     = $this->makeObj('object_modern',     'Baden-Württemberg', []);
        $resolver = new GovHierarchyResolver($this->stubClient([
            'object_leaf'       => $leaf,
            'object_historical' => $historical,
            'object_modern'     => $modern,
        ]));

        $current = $resolver->resolve('object_leaf', GovHierarchyResolver::MODE_CURRENT);
        self::assertCount(2, $current);
        self::assertSame('object_modern', $current[1]->govId);

        $hist = $resolver->resolve('object_leaf', GovHierarchyResolver::MODE_HISTORICAL);
        self::assertCount(2, $hist);
        self::assertSame('object_historical', $hist[1]->govId);
    }

    public function testCurrentModeUsesOpenEndedPartOfWhenLocatedInEmpty(): void
    {
        // Echte Oberurbach-Struktur (verifiziert an GOV OBEACH_W7067): located-in leer,
        // partOf mit drei Zeitspannen — der OFFENE (ab 1970) ist die heutige Zugehörigkeit.
        $leaf = new GovObject(
            'OBEACH_W7067', 'Oberurbach', ['deu' => 'Oberurbach'], [], null, null,
            ['object_306665', 'URBACHJN48TT', 'object_190778'], [], [], [],
            [
                'object_306665' => ['begin' => '1938', 'end' => '1970'],
                'URBACHJN48TT'  => ['begin' => '1970', 'end' => null],   // offen → heute
                'object_190778' => ['begin' => null,   'end' => '1938'],
            ],
        );
        $resolver = new GovHierarchyResolver($this->stubClient([
            'OBEACH_W7067'  => $leaf,
            'URBACHJN48TT'  => $this->makeObj('URBACHJN48TT',  'Urbach',           []),
            'object_190778' => $this->makeObj('object_190778', 'Oberamt Schorndorf', []),
        ]));

        $current = $resolver->resolve('OBEACH_W7067', GovHierarchyResolver::MODE_CURRENT);
        self::assertCount(2, $current);
        self::assertSame('URBACHJN48TT', $current[1]->govId); // heute: Urbach

        $hist = $resolver->resolveWithEdges('OBEACH_W7067', GovHierarchyResolver::MODE_HISTORICAL);
        self::assertCount(2, $hist);
        self::assertSame('object_190778', $hist[1]['obj']->govId); // ältester: bis 1938
        self::assertSame('1938', $hist[1]['end']);                 // Zeitspanne kommt jetzt an
    }

    public function testResolveWithEdgesHandlesMissingPartOfMeta(): void
    {
        $leaf = new GovObject('object_leaf', 'Weiler', ['deu' => 'Weiler'], [], null, null,
            ['object_root'], [], [], [], []); // partOfMeta leer
        $root = new GovObject('object_root', 'Land', ['deu' => 'Land'], [], null, null,
            [], [], [], [], []);

        $resolver = new GovHierarchyResolver($this->stubClient([
            'object_leaf' => $leaf,
            'object_root' => $root,
        ]));

        $edges = $resolver->resolveWithEdges('object_leaf');

        self::assertCount(2, $edges);
        self::assertNull($edges[1]['begin']);
        self::assertNull($edges[1]['end']);
    }

    /**
     * @param list<string> $partOfIds
     */
    private function makeObj(string $govId, string $name, array $partOfIds): GovObject
    {
        return new GovObject(
            govId:        $govId,
            primaryName:  $name,
            namesByLang:  ['deu' => $name],
            typeIds:      [],
            latitude:     null,
            longitude:    null,
            partOfIds:    $partOfIds,
            locatedInIds: [],
            externalUrls: [],
            rawJson:      [],
        );
    }
}
