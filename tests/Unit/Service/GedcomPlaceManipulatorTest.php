<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Dto\SubtagConflict;
use Ortsregister\Service\GedcomPlaceManipulator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GedcomPlaceManipulator::class)]
final class GedcomPlaceManipulatorTest extends TestCase
{
    private GedcomPlaceManipulator $manipulator;

    protected function setUp(): void
    {
        $this->manipulator = new GedcomPlaceManipulator();
    }

    // ---------- replacePlacBlock ----------

    public function testReplaceSimplePlacValueWithoutSubtags(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hambvurg\n1 SEX M";
        $out    = $this->manipulator->replacePlacBlock($gedcom, 'Hambvurg', 'Hamburg');
        self::assertSame("0 @I1@ INDI\n1 BIRT\n2 PLAC Hamburg\n1 SEX M", $out);
    }

    public function testReplaceKeepsOpaqueSourceSubtagsWhenNoConflict(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hambvurg\n3 _LOC @L42@\n3 MAP\n4 LATI 53.55\n4 LONG 9.99";
        $out = $this->manipulator->replacePlacBlock($gedcom, 'Hambvurg', 'Hamburg');
        self::assertStringContainsString("2 PLAC Hamburg", $out);
        self::assertStringContainsString("3 _LOC @L42@", $out);
        self::assertStringContainsString("3 MAP", $out);
        self::assertStringContainsString("4 LATI 53.55", $out);
        self::assertStringContainsString("4 LONG 9.99", $out);
    }

    public function testReplaceWithTargetResolutionUsesTargetSubtag(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hambvurg\n3 _LOC @L42@";
        $out = $this->manipulator->replacePlacBlock(
            $gedcom,
            'Hambvurg',
            'Hamburg',
            ['_LOC' => ['@L7@']],
            ['_LOC' => SubtagConflict::RESOLUTION_TARGET],
        );
        self::assertStringContainsString("3 _LOC @L7@", $out);
        self::assertStringNotContainsString("_LOC @L42@", $out);
    }

    public function testReplaceWithSourceResolutionKeepsSourceSubtag(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hambvurg\n3 _LOC @L42@";
        $out = $this->manipulator->replacePlacBlock(
            $gedcom,
            'Hambvurg',
            'Hamburg',
            ['_LOC' => ['@L7@']],
            ['_LOC' => SubtagConflict::RESOLUTION_SOURCE],
        );
        self::assertStringContainsString("3 _LOC @L42@", $out);
    }

    public function testReplaceWithDropResolutionRemovesSubtag(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hambvurg\n3 _LOC @L42@\n3 MAP\n4 LATI 53.55\n4 LONG 9.99";
        $out = $this->manipulator->replacePlacBlock(
            $gedcom,
            'Hambvurg',
            'Hamburg',
            ['_LOC' => ['@L7@']],
            ['_LOC' => SubtagConflict::RESOLUTION_DROP],
        );
        self::assertStringNotContainsString("_LOC", $out);
        // MAP bleibt: kein Konflikt erklärt
        self::assertStringContainsString("3 MAP", $out);
    }

    public function testReplaceAddsTargetSubtagsThatSourceLacks(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hambvurg";
        $out = $this->manipulator->replacePlacBlock(
            $gedcom,
            'Hambvurg',
            'Hamburg',
            ['_LOC' => ['@L7@'], 'MAP' => ['']],
        );
        self::assertStringContainsString("2 PLAC Hamburg", $out);
        self::assertStringContainsString("3 _LOC @L7@", $out);
        self::assertStringContainsString("3 MAP", $out);
    }

    public function testReplaceUnaffectedRecordsStayIdentical(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Berlin\n1 SEX M";
        $out = $this->manipulator->replacePlacBlock($gedcom, 'Hambvurg', 'Hamburg');
        self::assertSame($gedcom, $out);
    }

    public function testReplaceMultipleOccurrencesInOneRecord(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hambvurg\n1 DEAT\n2 PLAC Hambvurg";
        $out = $this->manipulator->replacePlacBlock($gedcom, 'Hambvurg', 'Hamburg');
        self::assertSame(2, substr_count($out, '2 PLAC Hamburg'));
        self::assertStringNotContainsString('Hambvurg', $out);
    }

    // ---------- extractDirectSubtags ----------

    public function testExtractDirectSubtagsReturnsTagToValuesMap(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hamburg\n3 _LOC @L42@\n3 MAP\n4 LATI 53.55";
        $result = $this->manipulator->extractDirectSubtags($gedcom, 'Hamburg');
        self::assertSame(['@L42@'], $result['_LOC']);
        self::assertArrayHasKey('MAP', $result);
    }

    public function testExtractDirectSubtagsIgnoresSubSubtags(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hamburg\n3 MAP\n4 LATI 53.55";
        $result = $this->manipulator->extractDirectSubtags($gedcom, 'Hamburg');
        // LATI ist Sub-Sub, sollte nicht direkt erscheinen
        self::assertArrayNotHasKey('LATI', $result);
    }

    public function testExtractDirectSubtagsReturnsEmptyForUnknownValue(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hamburg";
        $result = $this->manipulator->extractDirectSubtags($gedcom, 'Berlin');
        self::assertSame([], $result);
    }

    // ---------- detectConflicts ----------

    public function testDetectConflictsReturnsEmptyWhenAllIdentical(): void
    {
        $src = ['_LOC' => ['@L42@'], 'MAP' => ['']];
        $dst = ['_LOC' => ['@L42@'], 'MAP' => ['']];
        self::assertSame([], $this->manipulator->detectConflicts($src, $dst));
    }

    public function testDetectConflictsReturnsEmptyWhenTagOnlyInOneSide(): void
    {
        $src = ['_GOV' => ['object_123']];
        $dst = ['MAP' => ['']];
        self::assertSame([], $this->manipulator->detectConflicts($src, $dst));
    }

    public function testDetectConflictsReturnsConflictForDifferentValues(): void
    {
        $src = ['_LOC' => ['@L42@']];
        $dst = ['_LOC' => ['@L7@']];
        $conflicts = $this->manipulator->detectConflicts($src, $dst);
        self::assertCount(1, $conflicts);
        self::assertSame('_LOC', $conflicts[0]->tag);
        self::assertSame('@L42@', $conflicts[0]->sourceValue);
        self::assertSame('@L7@', $conflicts[0]->targetValue);
    }
}
