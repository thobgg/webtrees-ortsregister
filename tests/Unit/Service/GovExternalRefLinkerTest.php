<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\GovExternalRefLinker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GovExternalRefLinker::class)]
final class GovExternalRefLinkerTest extends TestCase
{
    private GovExternalRefLinker $linker;

    protected function setUp(): void
    {
        $this->linker = new GovExternalRefLinker();
    }

    public function testKnownSystemsResolveToVerifiedUrls(): void
    {
        self::assertSame(
            ['label' => 'GND', 'id' => '4015575-4', 'url' => 'https://d-nb.info/gnd/4015575-4'],
            $this->linker->resolve('GND:4015575-4'),
        );
        self::assertSame(
            ['label' => 'GeoNames', 'id' => '2930035', 'url' => 'https://www.geonames.org/2930035'],
            $this->linker->resolve('geonames:2930035'),
        );
        self::assertSame(
            ['label' => 'Wikidata', 'id' => 'Q880749', 'url' => 'https://www.wikidata.org/wiki/Q880749'],
            $this->linker->resolve('wikidata:Q880749'),
        );
        self::assertSame(
            ['label' => 'LEO-BW', 'id' => '331', 'url' => 'https://www.leo-bw.de/detail/-/Detail/details/ORT/331'],
            $this->linker->resolve('leobw:331'),
        );
    }

    public function testPrefixIsCaseInsensitive(): void
    {
        self::assertSame('https://d-nb.info/gnd/118', $this->linker->resolve('gnd:118')['url']);
        self::assertSame('https://www.wikidata.org/wiki/Q1', $this->linker->resolve('WIKIDATA:Q1')['url']);
    }

    /** Nicht kuratierte / tote Systeme werden bewusst NICHT angezeigt (Übersichtlichkeit). */
    public function testUncuratedSystemsAreDropped(): void
    {
        self::assertNull($this->linker->resolve('opengeodb:16357'));
        self::assertNull($this->linker->resolve('viaf:12345'));
        self::assertNull($this->linker->resolve('https://www.openstreetmap.org/relation/62651'));
        self::assertNull($this->linker->resolve('nurtext-ohne-doppelpunkt'));
        self::assertNull($this->linker->resolve('gnd:'));
        self::assertNull($this->linker->resolve(''));
        self::assertNull($this->linker->resolve('   '));
    }

    public function testResolveAllFiltersDropsAndDeduplicates(): void
    {
        $refs = [
            'opengeodb:16357',      // verworfen
            'leobw:331',            // ok
            'GND:4015575-4',        // ok
            'gnd:4015575-4',        // Duplikat (case) → weg
            'viaf:999',             // verworfen
        ];

        $out = $this->linker->resolveAll($refs);

        self::assertCount(2, $out);
        self::assertSame('LEO-BW', $out[0]['label']);
        self::assertSame('GND', $out[1]['label']);
    }

    /** IDs werden URL-sicher eingesetzt (keine kaputten Links bei Sonderzeichen). */
    public function testIdIsUrlEncoded(): void
    {
        $r = $this->linker->resolve('gnd:foo bar');
        self::assertSame('https://d-nb.info/gnd/foo%20bar', $r['url']);
    }
}
