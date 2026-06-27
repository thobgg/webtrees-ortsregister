<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\PlaceEventCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(PlaceEventCounter::class)]
final class PlaceEventCounterTest extends TestCase
{
    private PlaceEventCounter $counter;
    private ReflectionMethod  $extract;

    protected function setUp(): void
    {
        $this->counter = new PlaceEventCounter();
        $this->extract = new ReflectionMethod(PlaceEventCounter::class, 'extractEventTags');
        $this->extract->setAccessible(true);
    }

    public function testExtractsBirtAndDeatForMatchingLeaf(): void
    {
        $gedcom = <<<GED
            0 @I1@ INDI
            1 BIRT
            2 DATE 1 JAN 1850
            2 PLAC Haberschlacht, Brackenheim, Hzm. Württemberg
            1 DEAT
            2 PLAC Haberschlacht
            GED;
        $tags = $this->call($gedcom, 'Haberschlacht');
        self::assertSame(['BIRT', 'DEAT'], $tags);
    }

    public function testIgnoresEventsAtOtherPlaces(): void
    {
        $gedcom = <<<GED
            0 @I1@ INDI
            1 BIRT
            2 PLAC Stuttgart, Hzm. Württemberg
            1 DEAT
            2 PLAC Haberschlacht
            GED;
        $tags = $this->call($gedcom, 'Haberschlacht');
        self::assertSame(['DEAT'], $tags);
    }

    public function testMatchesOnLeafSegmentOnly(): void
    {
        $gedcom = <<<GED
            0 @I1@ INDI
            1 BIRT
            2 PLAC Brackenheim, Haberschlacht, X
            GED;
        $tags = $this->call($gedcom, 'Haberschlacht');
        // Haberschlacht steht hier NICHT am Anfang — soll nicht zählen
        self::assertSame([], $tags);
    }

    public function testIgnoresPointerLinesLikeFamc(): void
    {
        $gedcom = <<<GED
            0 @I1@ INDI
            1 FAMC @F1@
            2 PEDI birth
            1 BIRT
            2 PLAC Haberschlacht
            GED;
        $tags = $this->call($gedcom, 'Haberschlacht');
        self::assertSame(['BIRT'], $tags);
    }

    public function testHandlesEmptyGedcom(): void
    {
        self::assertSame([], $this->call('', 'Haberschlacht'));
    }

    public function testHandlesCustomEventTags(): void
    {
        $gedcom = <<<GED
            0 @I1@ INDI
            1 EMIG
            2 PLAC Haberschlacht
            1 OCCU
            2 PLAC Haberschlacht
            GED;
        $tags = $this->call($gedcom, 'Haberschlacht');
        self::assertSame(['EMIG', 'OCCU'], $tags);
    }

    private function call(string $gedcom, string $leafName): array
    {
        return $this->extract->invoke($this->counter, $gedcom, $leafName);
    }
}
