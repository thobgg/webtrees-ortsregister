<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\LocationReader;
use Ortsregister\Service\LocationWriter;
use Ortsregister\Service\OperationBackup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reiner Kern für „Ortsbeschreibung → `_LOC` NOTE" (erster Zug der Daten-Doktrin):
 * der Reader liest die inline-NOTE, der Writer setzt/ersetzt/entfernt sie — additiv
 * zu NAME/_GOV/MAP, Pointer-Notizen bleiben unberührt. DB-frei, isoliert.
 */
#[CoversClass(LocationReader::class)]
#[CoversClass(LocationWriter::class)]
final class LocationNoteTest extends TestCase
{
    private function reader(): LocationReader
    {
        return new LocationReader();
    }

    private function writer(): LocationWriter
    {
        return new LocationWriter(new LocationReader(), new OperationBackup(sys_get_temp_dir()));
    }

    // ---------- Reader ----------

    public function testReadsSingleLineNote(): void
    {
        $id = $this->reader()->parse('L1', "0 @L1@ _LOC\n1 NAME Pleidelsheim\n1 NOTE Am Neckar gelegen.");
        self::assertSame('Am Neckar gelegen.', $id->primaryNote());
    }

    public function testReadsMultilineNoteViaCont(): void
    {
        $gedcom = "0 @L1@ _LOC\n1 NAME Pleidelsheim\n1 NOTE Zeile eins\n2 CONT Zeile zwei\n2 CONT\n2 CONT Zeile vier";
        $id = $this->reader()->parse('L1', $gedcom);
        self::assertSame("Zeile eins\nZeile zwei\n\nZeile vier", $id->primaryNote());
    }

    public function testSkipsPointerNote(): void
    {
        $id = $this->reader()->parse('L1', "0 @L1@ _LOC\n1 NAME X\n1 NOTE @N1@");
        self::assertSame([], $id->notes);
        self::assertNull($id->primaryNote());
    }

    // ---------- Writer ----------

    public function testAddsNoteWhenNoneExists(): void
    {
        $out = $this->writer()->setInlineNote("0 @L1@ _LOC\n1 NAME X", 'Hallo Welt');
        self::assertStringContainsString("\n1 NOTE Hallo Welt", $out);
        self::assertStringContainsString('1 NAME X', $out);
    }

    public function testReplacesExistingInlineNoteAndKeepsIdentity(): void
    {
        $in  = "0 @L1@ _LOC\n1 NAME X\n1 NOTE alt\n2 CONT alt2\n1 _GOV G1";
        $out = $this->writer()->setInlineNote($in, 'neu');
        self::assertStringNotContainsString('alt', $out);
        self::assertStringContainsString('1 NOTE neu', $out);
        self::assertStringContainsString('1 NAME X', $out);
        self::assertStringContainsString('1 _GOV G1', $out);
    }

    public function testRemovesNoteOnEmptyOrNull(): void
    {
        $in = "0 @L1@ _LOC\n1 NAME X\n1 NOTE weg\n2 CONT auch weg\n1 _GOV G1";
        foreach (['', null] as $empty) {
            $out = $this->writer()->setInlineNote($in, $empty);
            self::assertStringNotContainsString('NOTE', $out);
            self::assertStringContainsString('1 NAME X', $out);
            self::assertStringContainsString('1 _GOV G1', $out);
        }
    }

    public function testKeepsPointerNoteButAddsInline(): void
    {
        $out = $this->writer()->setInlineNote("0 @L1@ _LOC\n1 NAME X\n1 NOTE @N1@", 'frisch');
        self::assertStringContainsString('1 NOTE @N1@', $out);
        self::assertStringContainsString('1 NOTE frisch', $out);
    }

    public function testMultilineFoldsToCont(): void
    {
        $out = $this->writer()->setInlineNote("0 @L1@ _LOC\n1 NAME X", "a\nb");
        self::assertStringContainsString("1 NOTE a\n2 CONT b", $out);
    }

    public function testRoundTripPreservesText(): void
    {
        $text = "Markdown geht:\n\n- [ ] Kirchenbuch prüfen\n- [x] Foto abgelegt";
        $out  = $this->writer()->setInlineNote("0 @L1@ _LOC\n1 NAME X", $text);
        $back = $this->reader()->parse('L1', $out);
        self::assertSame($text, $back->primaryNote());
    }
}
