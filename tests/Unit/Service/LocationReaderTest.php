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

    /**
     * Issue #12 Dedup-Regel: die _LOC-Inhaltsanzeige zeigt bei gebundenem Record die
     * ZWEITE Notiz aufwärts (die erste = Beschreibung, eigene Karte). primaryNote und
     * secondaryNotes müssen sich sauber teilen.
     */
    public function testMultipleInlineNotesSplitIntoPrimaryAndSecondary(): void
    {
        $gedcom = implode("\n", [
            '0 @L8@ _LOC',
            '1 NAME Ort',
            '1 NOTE Beschreibung des Orts',
            '2 CONT Zweite Zeile der Beschreibung',
            '1 NOTE Quellenhinweis aus fremdem System',
            '1 NOTE Dritte Notiz',
        ]);

        $id = $this->reader->parse('@L8@', $gedcom);

        self::assertSame("Beschreibung des Orts\nZweite Zeile der Beschreibung", $id->primaryNote());
        self::assertSame(
            ['Quellenhinweis aus fremdem System', 'Dritte Notiz'],
            $id->secondaryNotes(),
            'Bei gebundenem Record dürfen nur die Notizen NACH der Beschreibung angezeigt werden.',
        );
    }

    /** Nur die Beschreibung vorhanden → keine sekundären Notizen → keine „📝"-Zeile. */
    public function testSingleInlineNoteHasNoSecondaryNotes(): void
    {
        $gedcom = implode("\n", [
            '0 @L9@ _LOC',
            '1 NAME Ort',
            '1 NOTE Nur die Beschreibung',
        ]);

        $id = $this->reader->parse('@L9@', $gedcom);

        self::assertSame('Nur die Beschreibung', $id->primaryNote());
        self::assertSame([], $id->secondaryNotes());
    }

    /** Pointer-Notizen (`1 NOTE @N1@`) zählen nicht als inline-Beschreibung. */
    public function testPointerNotesAreNotTreatedAsInlineNotes(): void
    {
        $gedcom = implode("\n", [
            '0 @L10@ _LOC',
            '1 NAME Ort',
            '1 NOTE @N1@',
            '1 NOTE Echte inline-Notiz',
        ]);

        $id = $this->reader->parse('@L10@', $gedcom);

        self::assertSame('Echte inline-Notiz', $id->primaryNote());
        self::assertSame([], $id->secondaryNotes());
    }

    /** Issue #12 Scheibe 2: `_LOC:EVEN` → TYPE/DATE/PLAC gespiegelt. */
    public function testParsesLocEvents(): void
    {
        $gedcom = implode("\n", [
            '0 @L11@ _LOC',
            '1 NAME Ort',
            '1 EVEN',
            '2 TYPE Ersterwähnung',
            '2 DATE 1250',
            '2 PLAC Ort, Kreis, Land',
            '1 EVEN',
            '2 TYPE Eingemeindung',
            '2 DATE 1 JAN 1972',
        ]);

        $id = $this->reader->parse('@L11@', $gedcom);

        self::assertCount(2, $id->events);
        self::assertSame('Ersterwähnung', $id->events[0]->type);
        self::assertSame('1250', $id->events[0]->date);
        self::assertSame('Ort, Kreis, Land', $id->events[0]->place);
        self::assertSame('Eingemeindung', $id->events[1]->type);
        self::assertSame('1 JAN 1972', $id->events[1]->date);
        self::assertNull($id->events[1]->place);
    }

    /** Issue #12 Scheibe 2: `_LOC:_DMGD` → Wert/TYPE/DATE (Einwohnerzahl). */
    public function testParsesLocDemographics(): void
    {
        $gedcom = implode("\n", [
            '0 @L12@ _LOC',
            '1 NAME Ort',
            '1 _DMGD 1234',
            '2 TYPE Einwohner',
            '2 DATE 1900',
            '1 _DMGD 2048',
            '2 DATE 2020',
        ]);

        $id = $this->reader->parse('@L12@', $gedcom);

        self::assertCount(2, $id->demographics);
        self::assertSame('1234', $id->demographics[0]->value);
        self::assertSame('Einwohner', $id->demographics[0]->type);
        self::assertSame('1900', $id->demographics[0]->date);
        self::assertSame('2048', $id->demographics[1]->value);
        self::assertNull($id->demographics[1]->type);
        self::assertSame('2020', $id->demographics[1]->date);
    }

    /** Level-3-Kinder (z.B. `EVEN:PLAC:MAP:LATI`) dürfen die Level-2-Felder nicht verfälschen. */
    public function testEventLevelThreeChildrenIgnored(): void
    {
        $gedcom = implode("\n", [
            '0 @L13@ _LOC',
            '1 NAME Ort',
            '1 EVEN',
            '2 TYPE Ereignis',
            '2 PLAC Irgendwo',
            '3 MAP',
            '4 LATI N49.0',
            '4 LONG E9.0',
            '2 DATE 1800',
        ]);

        $id = $this->reader->parse('@L13@', $gedcom);

        self::assertCount(1, $id->events);
        self::assertSame('Ereignis', $id->events[0]->type);
        self::assertSame('Irgendwo', $id->events[0]->place);
        self::assertSame('1800', $id->events[0]->date);
    }

    /** _DMGD ohne Wert auf der Tag-Zeile wird verworfen (kein leerer Eintrag). */
    public function testDemographicWithoutValueIsDropped(): void
    {
        $gedcom = implode("\n", [
            '0 @L14@ _LOC',
            '1 NAME Ort',
            '1 _DMGD',
            '2 DATE 1900',
        ]);

        $id = $this->reader->parse('@L14@', $gedcom);

        self::assertSame([], $id->demographics);
    }
}
