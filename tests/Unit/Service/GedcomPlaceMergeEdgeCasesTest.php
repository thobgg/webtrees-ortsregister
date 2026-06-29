<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\GedcomPlaceManipulator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Härtungs-Tests für replacePlacBlock gegen chaotische Real-Daten —
 * abgeleitet aus dem Brisbane-Vorfall (2026-06-29). Diese Fälle sind die
 * Super-GAU-Quelle: stilles GEDCOM-Mangling beim Merge.
 */
#[CoversClass(GedcomPlaceManipulator::class)]
final class GedcomPlaceMergeEdgeCasesTest extends TestCase
{
    private GedcomPlaceManipulator $m;

    protected function setUp(): void
    {
        $this->m = new GedcomPlaceManipulator();
    }

    /**
     * Compound-PLAC mit "; " (echte Rosina-Dilger-Daten): die Quelle steht als
     * SUFFIX am Ende → nur dieser Teil wird ersetzt, der davorstehende
     * "Coorparoo, …"-Teil bleibt unangetastet. KEIN Mangling.
     */
    public function testCompoundPlacOnlyReplacesTrailingSuffix(): void
    {
        $gedcom = "0 @I1@ INDI\n"
            . "1 BIRT\n2 PLAC Coorparoo, Queensland, Australia ; Cleveland Road, Stanley, Brisbane, Queensland, Australia\n"
            . "1 DEAT\n2 PLAC Goodna, Queensland, Australia";

        $out = $this->m->replacePlacBlock(
            $gedcom,
            'Brisbane, Queensland, Australia',
            'Neustadt, Queensland, Australia',
        );

        // Suffix ersetzt, Prefix erhalten
        self::assertStringContainsString(
            '2 PLAC Coorparoo, Queensland, Australia ; Cleveland Road, Stanley, Neustadt, Queensland, Australia',
            $out,
        );
        // Goodna teilt "Queensland, Australia", ist aber NICHT Brisbane-Suffix → unangetastet
        self::assertStringContainsString('2 PLAC Goodna, Queensland, Australia', $out);
    }

    /**
     * Over-Capture-Schutz: ein Geschwister-Ort, der nur die oberen Ebenen teilt
     * ("Haigslea, Queensland, Australia"), darf beim Brisbane-Merge NICHT
     * angefasst werden.
     */
    public function testSiblingPlaceSharingUpperLevelsNotTouched(): void
    {
        $gedcom = "0 @I1@ INDI\n"
            . "1 DEAT\n2 PLAC Haigslea, Queensland, Australia\n"
            . "1 BURI\n2 PLAC Brisbane, Queensland, Australia";

        $out = $this->m->replacePlacBlock(
            $gedcom,
            'Brisbane, Queensland, Australia',
            'Brisbane (Stadt), Queensland, Australia',
        );

        self::assertStringContainsString('2 PLAC Haigslea, Queensland, Australia', $out);  // unverändert
        self::assertStringContainsString('2 PLAC Brisbane (Stadt), Queensland, Australia', $out);
        self::assertStringNotContainsString('PLAC Brisbane, Queensland, Australia', $out); // alt weg
    }

    /**
     * Degenerierter Trailing-Dot-Merge ("X" → "X.") rewritet mechanisch korrekt
     * (exakter Match). Der Wächter dagegen sitzt in analyzeMerge, nicht hier.
     */
    public function testTrailingDotMergeReplacesExact(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Brisbane, Queensland, Australia";
        $out = $this->m->replacePlacBlock(
            $gedcom,
            'Brisbane, Queensland, Australia',
            'Brisbane, Queensland, Australia.',
        );
        self::assertSame(
            "0 @I1@ INDI\n1 BIRT\n2 PLAC Brisbane, Queensland, Australia.",
            $out,
        );
    }

    /**
     * Substring-Falle: "Berlin" darf weder "Berlinchen" (kein Wort-/Komma-Grenze)
     * noch "Neu-Berlin" anfassen, aber sehr wohl den echten Suffix ", Berlin".
     */
    public function testSubstringNotMatchedOnlyExactOrCommaSuffix(): void
    {
        $gedcom = "0 @I1@ INDI\n"
            . "1 BIRT\n2 PLAC Berlinchen\n"
            . "1 RESI\n2 PLAC Neu-Berlin\n"
            . "1 DEAT\n2 PLAC Vorort, Berlin";

        $out = $this->m->replacePlacBlock($gedcom, 'Berlin', 'Berlin City');

        self::assertStringContainsString('2 PLAC Berlinchen', $out);   // unangetastet
        self::assertStringContainsString('2 PLAC Neu-Berlin', $out);   // unangetastet
        self::assertStringContainsString('2 PLAC Vorort, Berlin City', $out); // Suffix ersetzt
    }

    /**
     * DOKUMENTIERTE GRENZE (kein Bug, aber bewusst festgehalten): steht die
     * Quelle in einem Compound NICHT am Ende, greift der Suffix-Match nicht →
     * die Zeile bleibt unverändert. So ein Record würde beim Merge nicht
     * mitwandern. Real selten (PLAC endet i.d.R. auf der höchsten Ebene),
     * aber für Tester relevant zu wissen.
     */
    public function testSourceInMiddleOfCompoundIsNotRewritten_documentedLimitation(): void
    {
        $gedcom = "0 @I1@ INDI\n1 BIRT\n2 PLAC Brisbane, Queensland, Australia ; weiterer Text";
        $out = $this->m->replacePlacBlock(
            $gedcom,
            'Brisbane, Queensland, Australia',
            'Neustadt, Queensland, Australia',
        );
        // bleibt unverändert — Suffix-Match greift nur am Zeilenende
        self::assertSame($gedcom, $out);
    }
}
