<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Dto;

use Ortsregister\Dto\GovObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GovObject::class)]
final class GovObjectTest extends TestCase
{
    public function testHasCoordinatesTrueWhenBothSet(): void
    {
        $o = $this->make(lat: 48.0, lng: 9.0);
        self::assertTrue($o->hasCoordinates());
    }

    public function testHasCoordinatesFalseWhenAnyMissing(): void
    {
        self::assertFalse($this->make(lat: null, lng: 9.0)->hasCoordinates());
        self::assertFalse($this->make(lat: 48.0, lng: null)->hasCoordinates());
    }

    public function testOsmRelationIdsExtractsAllFromExternalUrls(): void
    {
        $o = $this->make(externalUrls: [
            'https://www.openstreetmap.org/relation/62782',
            'https://de.wikipedia.org/wiki/Hamburg',
            'https://www.openstreetmap.org/relation/12345',
            'https://www.wikidata.org/wiki/Q1055',
        ]);
        self::assertSame([62782, 12345], $o->osmRelationIds());
    }

    public function testOsmRelationIdsEmptyWhenNoOsm(): void
    {
        $o = $this->make(externalUrls: ['https://de.wikipedia.org/wiki/Hamburg']);
        self::assertSame([], $o->osmRelationIds());
    }

    /**
     * @param array<string, string> $namesByLang
     * @param list<string>          $typeIds
     * @param list<string>          $partOfIds
     * @param list<string>          $locatedInIds
     * @param list<string>          $externalUrls
     */
    private function make(
        string $govId = 'object_1',
        string $primaryName = 'Test',
        array  $namesByLang = [],
        array  $typeIds = [],
        ?float $lat = null,
        ?float $lng = null,
        array  $partOfIds = [],
        array  $locatedInIds = [],
        array  $externalUrls = [],
    ): GovObject {
        return new GovObject(
            govId:        $govId,
            primaryName:  $primaryName,
            namesByLang:  $namesByLang,
            typeIds:      $typeIds,
            latitude:     $lat,
            longitude:    $lng,
            partOfIds:    $partOfIds,
            locatedInIds: $locatedInIds,
            externalUrls: $externalUrls,
            rawJson:      [],
        );
    }
}
