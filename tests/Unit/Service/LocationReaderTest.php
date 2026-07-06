<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Dto\LocationIdentity;
use Ortsregister\Service\LocationReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocationReader::class)]
#[CoversClass(LocationIdentity::class)]
final class LocationReaderTest extends TestCase
{
    private LocationReader $reader;

    protected function setUp(): void
    {
        $this->reader = new LocationReader();
    }

    public function testParsesFullVerifiedGrammar(): void
    {
        $gedcom = implode("\n", [
            '0 @L1@ _LOC',
            '1 NAME Weiler',
            '2 DATE FROM 1700 TO 1850',
            '2 LANG de',
            '1 NAME Wyler',
            '1 TYPE Village',
            '1 MAP',
            '2 LATI N49.1234',
            '2 LONG E9.1234',
            '1 _GOV WEILER_W1234',
            '1 _LOC @L2@',
            '2 TYPE political',
        ]);

        $id = $this->reader->parse('@L1@', $gedcom);

        self::assertSame('L1', $id->xref);
        self::assertSame(['Weiler', 'Wyler'], $id->names);
        self::assertSame('Weiler', $id->primaryName());
        self::assertSame('Village', $id->type);
        self::assertSame('WEILER_W1234', $id->govId);
        self::assertTrue($id->hasGov());
        self::assertTrue($id->hasCoordinates());
        self::assertEqualsWithDelta(49.1234, $id->latitude, 1e-9);
        self::assertEqualsWithDelta(9.1234, $id->longitude, 1e-9);
        self::assertSame(['L2'], $id->parentXrefs);
        self::assertFalse($id->isEmpty());
    }

    public function testParsesSouthWestHemispheres(): void
    {
        $gedcom = implode("\n", [
            '0 @L9@ _LOC',
            '1 NAME Ushuaia',
            '1 MAP',
            '2 LATI S54.8019',
            '2 LONG W68.3030',
        ]);

        $id = $this->reader->parse('@L9@', $gedcom);

        self::assertTrue($id->hasCoordinates());
        self::assertEqualsWithDelta(-54.8019, $id->latitude, 1e-9);
        self::assertEqualsWithDelta(-68.3030, $id->longitude, 1e-9);
    }

    public function testMinimalRecordIsNotEmptyButHasOnlyName(): void
    {
        $id = $this->reader->parse('@L2@', "0 @L2@ _LOC\n1 NAME Haberschlacht");

        self::assertSame(['Haberschlacht'], $id->names);
        self::assertNull($id->govId);
        self::assertNull($id->type);
        self::assertFalse($id->hasCoordinates());
        self::assertSame([], $id->parentXrefs);
        self::assertFalse($id->isEmpty());
    }

    public function testEmptyStubRecord(): void
    {
        $id = $this->reader->parse('@L3@', '0 @L3@ _LOC');

        self::assertSame('L3', $id->xref);
        self::assertSame([], $id->names);
        self::assertSame('', $id->primaryName());
        self::assertTrue($id->isEmpty());
    }

    public function testHalfCoordinatePairIsDroppedAsInvalid(): void
    {
        $gedcom = "0 @L4@ _LOC\n1 NAME Foo\n1 MAP\n2 LATI N49.0";
        $id     = $this->reader->parse('@L4@', $gedcom);

        self::assertFalse($id->hasCoordinates());
        self::assertNull($id->latitude);
        self::assertNull($id->longitude);
    }

    public function testMultipleHierarchyPointersDeduplicated(): void
    {
        $gedcom = implode("\n", [
            '0 @L5@ _LOC',
            '1 NAME Ort',
            '1 _LOC @L2@',
            '1 _LOC @L2@',
            '1 _LOC @L3@',
        ]);

        $id = $this->reader->parse('@L5@', $gedcom);

        self::assertSame(['L2', 'L3'], $id->parentXrefs);
    }

    public function testLevelTwoNameInsideMapIsNotMistakenForRecordName(): void
    {
        // Ein NAME auf Level 2 (z.B. unter einem anderen Tag) darf NICHT als
        // Record-NAME gezählt werden — nur Level-1-NAME zählt.
        $gedcom = implode("\n", [
            '0 @L6@ _LOC',
            '1 NAME Echt',
            '1 TYPE Village',
            '2 NAME Verschachtelt',
        ]);

        $id = $this->reader->parse('@L6@', $gedcom);

        self::assertSame(['Echt'], $id->names);
    }

    public function testUnknownTagsIgnored(): void
    {
        $gedcom = implode("\n", [
            '0 @L7@ _LOC',
            '1 NAME Ort',
            '1 _UNKNOWN irgendwas',
            '1 RESN privacy',
        ]);

        $id = $this->reader->parse('@L7@', $gedcom);

        self::assertSame(['Ort'], $id->names);
        self::assertNull($id->govId);
        self::assertFalse($id->isEmpty());
    }
}
