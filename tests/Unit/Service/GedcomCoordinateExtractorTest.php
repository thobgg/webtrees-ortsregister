<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\GedcomCoordinateExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GedcomCoordinateExtractor::class)]
final class GedcomCoordinateExtractorTest extends TestCase
{
    private GedcomCoordinateExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new GedcomCoordinateExtractor();
    }

    public function testExtractStandardPlacWithMap(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hamburg\n3 MAP\n4 LATI N53.55\n4 LONG E9.99";
        $result = $this->extractor->extract($gedcom);
        self::assertCount(1, $result);
        self::assertSame('Hamburg', $result[0][0]);
        self::assertSame(53.55, $result[0][1]);
        self::assertSame(9.99, $result[0][2]);
    }

    public function testExtractSouthWestCoords(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Buenos Aires\n3 MAP\n4 LATI S34.6\n4 LONG W58.4";
        $result = $this->extractor->extract($gedcom);
        self::assertSame(-34.6, $result[0][1]);
        self::assertSame(-58.4, $result[0][2]);
    }

    public function testExtractBarePlacWithoutMap(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Berlin\n1 SEX M";
        $result = $this->extractor->extract($gedcom);
        self::assertSame([], $result);
    }

    public function testExtractMultiplePlacInOneRecord(): void
    {
        $gedcom = "0 @I1@ INDI\n"
            . "1 BIRT\n2 PLAC Hamburg\n3 MAP\n4 LATI N53.55\n4 LONG E9.99\n"
            . "1 DEAT\n2 PLAC Berlin\n3 MAP\n4 LATI N52.5\n4 LONG E13.4";
        $result = $this->extractor->extract($gedcom);
        self::assertCount(2, $result);
        self::assertSame('Hamburg', $result[0][0]);
        self::assertSame('Berlin', $result[1][0]);
    }

    public function testExtractIgnoresPlacWithoutBothCoords(): void
    {
        // nur LATI, kein LONG → wird verworfen
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hamburg\n3 MAP\n4 LATI N53.55";
        $result = $this->extractor->extract($gedcom);
        self::assertSame([], $result);
    }

    public function testExtractHandlesPlainNumericCoords(): void
    {
        // Ohne N/S/E/W-Prefix
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hamburg\n3 MAP\n4 LATI 53.55\n4 LONG 9.99";
        $result = $this->extractor->extract($gedcom);
        self::assertSame(53.55, $result[0][1]);
        self::assertSame(9.99, $result[0][2]);
    }
}
