<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\LocationEventLinker;
use Ortsregister\Service\LocationReader;
use Ortsregister\Service\OperationBackup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Der korrektheits-kritische Kern von W2: die GEDCOM-Chirurgie, die den
 * Ereignis→Ort-Zeiger `<L+1> _LOC @x@` unter die passende Ereignis-`PLAC` setzt.
 * DB-frei, rein — deckt genau die Garantien ab, die live sonst niemand prüft:
 *   - fügt unter die passende PLAC ein, Ebene = PLAC-Ebene + 1
 *   - lässt alles andere byte-identisch, erhält Reihenfolge (MAP etc. bleiben)
 *   - additiv/gap-fill: ein vorhandener Zeiger wird NIE überschrieben (idempotent)
 *   - matcht nur den vollen Pfad (kein Fehlgriff auf gleichnamige Ebenen), Spacing-tolerant
 */
#[CoversClass(LocationEventLinker::class)]
final class LocationEventLinkerTest extends TestCase
{
    private function linker(): LocationEventLinker
    {
        return new LocationEventLinker(new LocationReader(), new OperationBackup(sys_get_temp_dir()));
    }

    private const PATH = 'Weiler, Amt Kirchheim, Hzm. Württemberg';

    public function testInsertsPointerUnderMatchingPlac(): void
    {
        $gedcom = "0 @I1@ INDI\n1 NAME John /Doe/\n1 BIRT\n2 DATE 1 JAN 1850\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg";
        [$new, $added, $linked] = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');

        self::assertSame(1, $added);
        self::assertSame(0, $linked);
        self::assertStringContainsString("2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg\n3 _LOC @L1@", $new);
    }

    public function testPointerLevelIsOneDeeperThanPlac(): void
    {
        // Record-Ebene PLAC (1 PLAC) → Zeiger auf Ebene 2.
        $gedcom = "0 @L9@ _LOC\n1 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg";
        [$new, $added] = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');

        self::assertSame(1, $added);
        self::assertStringContainsString("1 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg\n2 _LOC @L1@", $new);
    }

    public function testPreservesExistingPlacSubordinates(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg\n3 MAP\n4 LATI N49.1\n4 LONG E9.1\n2 SOUR @S1@";
        [$new, $added] = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');

        self::assertSame(1, $added);
        // Zeiger direkt hinter PLAC, MAP-Block und SOUR unangetastet.
        self::assertStringContainsString("2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg\n3 _LOC @L1@\n3 MAP\n4 LATI N49.1\n4 LONG E9.1\n2 SOUR @S1@", $new);
    }

    public function testIdempotentDoesNotAddTwice(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg";
        [$once]  = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');
        [$twice, $added, $linked] = $this->linker()->addLocPointer($once, self::PATH, 'L1');

        self::assertSame($once, $twice);
        self::assertSame(0, $added);
        self::assertSame(1, $linked);
    }

    public function testNeverOverwritesForeignPointer(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg\n3 _LOC @L7@";
        [$new, $added, $linked] = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');

        self::assertSame($gedcom, $new);
        self::assertSame(0, $added);
        self::assertSame(1, $linked);
    }

    public function testDoesNotMatchDifferentPlace(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Amt Kirchheim, Hzm. Württemberg";
        [$new, $added, $linked] = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');

        self::assertSame($gedcom, $new);
        self::assertSame(0, $added);
        self::assertSame(0, $linked);
    }

    public function testMatchIsSpacingTolerant(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Weiler,Amt Kirchheim,Hzm. Württemberg";
        [, $added] = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');

        self::assertSame(1, $added);
    }

    public function testAddsToEveryMatchingEventInRecord(): void
    {
        $gedcom = "0 @I1@ INDI"
            . "\n1 BIRT\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg"
            . "\n1 DEAT\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg";
        [$new, $added] = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');

        self::assertSame(2, $added);
        self::assertSame(2, substr_count($new, '3 _LOC @L1@'));
    }

    public function testFamilyMarriage(): void
    {
        $gedcom = "0 @F1@ FAM\n1 MARR\n2 DATE 1875\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg";
        [$new, $added] = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');

        self::assertSame(1, $added);
        self::assertStringContainsString("2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg\n3 _LOC @L1@", $new);
    }

    public function testEmptyInputsAreNoOps(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg";
        self::assertSame([$gedcom, 0, 0], $this->linker()->addLocPointer($gedcom, self::PATH, ''));
        self::assertSame([$gedcom, 0, 0], $this->linker()->addLocPointer($gedcom, '', 'L1'));
    }

    public function testMixedLinkedAndUnlinkedEventsAreCountedSeparately(): void
    {
        $gedcom = "0 @I1@ INDI"
            . "\n1 BIRT\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg\n3 _LOC @L1@"  // schon verknüpft
            . "\n1 DEAT\n2 PLAC Weiler, Amt Kirchheim, Hzm. Württemberg";               // noch offen
        [, $added, $linked] = $this->linker()->addLocPointer($gedcom, self::PATH, 'L1');

        self::assertSame(1, $added);
        self::assertSame(1, $linked);
    }

    /**
     * Undo-Vergleich: ein Re-Import aktualisiert den CHAN-Zeitstempel. Der darf NICHT
     * als „Datensatz seither geändert" gelten — sonst würde jedes Undo fälschlich
     * übersprungen. Ein echter inhaltlicher Edit dagegen muss auffallen.
     */
    public function testNormalizeForCompareIgnoresChanButNotContent(): void
    {
        $m = new \ReflectionMethod(LocationEventLinker::class, 'normalizeForCompare');
        $linker = $this->linker();

        $withChanA = "0 @I1@ INDI\n1 NAME John /Doe/\n1 CHAN\n2 DATE 1 JAN 2020\n3 TIME 10:00:00";
        $withChanB = "0 @I1@ INDI\n1 NAME John /Doe/\n1 CHAN\n2 DATE 8 JUL 2026\n3 TIME 23:59:59";
        self::assertSame(
            $m->invoke($linker, $withChanA),
            $m->invoke($linker, $withChanB),
            'Unterschiedliche CHAN-Zeitstempel dürfen den Vergleich nicht beeinflussen.',
        );

        $edited = "0 @I1@ INDI\n1 NAME Jane /Doe/\n1 CHAN\n2 DATE 1 JAN 2020\n3 TIME 10:00:00";
        self::assertNotSame(
            $m->invoke($linker, $withChanA),
            $m->invoke($linker, $edited),
            'Ein echter inhaltlicher Edit muss trotz CHAN-Strippen auffallen.',
        );
    }
}
