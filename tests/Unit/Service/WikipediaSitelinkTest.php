<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Dto\WikimediaPlaceData;
use Ortsregister\Service\WikimediaPlaceClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Wikipedia-Link in Nutzersprache (Hermanns Issue): der Sitelink-Extraktor zieht die
 * Sprach→URL-Map aus der Wikidata-Entity, das DTO wählt die Nutzersprache mit Fallback.
 * Beides rein, DB-/netzfrei.
 */
#[CoversClass(WikimediaPlaceClient::class)]
#[CoversClass(WikimediaPlaceData::class)]
final class WikipediaSitelinkTest extends TestCase
{
    private function entity(): array
    {
        return ['entities' => ['Q123' => ['sitelinks' => [
            'dewiki'       => ['site' => 'dewiki', 'title' => 'Pleidelsheim', 'url' => 'https://de.wikipedia.org/wiki/Pleidelsheim'],
            'enwiki'       => ['site' => 'enwiki', 'title' => 'Pleidelsheim', 'url' => 'https://en.wikipedia.org/wiki/Pleidelsheim'],
            'nlwiki'       => ['site' => 'nlwiki', 'title' => 'Pleidelsheim', 'url' => 'https://nl.wikipedia.org/wiki/Pleidelsheim'],
            'commonswiki'  => ['site' => 'commonswiki', 'title' => 'Category:Pleidelsheim', 'url' => 'https://commons.wikimedia.org/wiki/Category:Pleidelsheim'],
            'enwikisource' => ['site' => 'enwikisource', 'title' => 'X', 'url' => 'https://en.wikisource.org/wiki/X'],
        ]]]];
    }

    private function client(): WikimediaPlaceClient
    {
        return (new ReflectionClass(WikimediaPlaceClient::class))->newInstanceWithoutConstructor();
    }

    public function testExtractsOnlyRealWikipedias(): void
    {
        $map = $this->client()->extractSitelinks($this->entity(), 'Q123');

        self::assertSame('https://de.wikipedia.org/wiki/Pleidelsheim', $map['de'] ?? null);
        self::assertSame('https://en.wikipedia.org/wiki/Pleidelsheim', $map['en'] ?? null);
        self::assertSame('https://nl.wikipedia.org/wiki/Pleidelsheim', $map['nl'] ?? null);
        // Schwester-Projekte raus:
        self::assertArrayNotHasKey('commons', $map);
        self::assertArrayNotHasKey('enwikisource', $map);
        self::assertArrayNotHasKey('en-source', $map);
    }

    public function testEmptyWhenNoSitelinks(): void
    {
        self::assertSame([], $this->client()->extractSitelinks(['entities' => ['Q123' => []]], 'Q123'));
    }

    public function testDtoPicksUserLanguage(): void
    {
        $dto = new WikimediaPlaceData('Q123', null, [], [
            'de' => 'https://de.wikipedia.org/wiki/Pleidelsheim',
            'en' => 'https://en.wikipedia.org/wiki/Pleidelsheim',
            'nl' => 'https://nl.wikipedia.org/wiki/Pleidelsheim',
        ]);

        self::assertStringStartsWith('https://nl.', $dto->wikipediaUrl('nl'));
        self::assertStringStartsWith('https://en.', $dto->wikipediaUrl('en-GB')); // Primär-Subtag
        self::assertStringStartsWith('https://de.', $dto->wikipediaUrl('fr'));    // Fallback de
    }

    public function testDtoFallsBackToAnyThenNull(): void
    {
        $only = new WikimediaPlaceData('Q1', null, [], ['nl' => 'https://nl.wikipedia.org/wiki/X']);
        self::assertSame('https://nl.wikipedia.org/wiki/X', $only->wikipediaUrl('fr')); // kein de/en → irgendeiner

        $none = new WikimediaPlaceData('Q1', null, [], []);
        self::assertNull($none->wikipediaUrl('de'));
    }

    /**
     * Cache-Altbestand (der Oberurbach-500): ein VOR dem Sitelinks-Feld gecachtes Objekt
     * kommt mit uninitialisierten Properties aus dem Cache zurück. wikipediaUrl() muss
     * dann null liefern statt mit einem Fatal die Ortsseite zu killen.
     */
    public function testStaleCachedObjectWithoutSitelinksReturnsNull(): void
    {
        $stale = (new ReflectionClass(WikimediaPlaceData::class))->newInstanceWithoutConstructor();
        self::assertNull($stale->wikipediaUrl('de'));
    }
}
