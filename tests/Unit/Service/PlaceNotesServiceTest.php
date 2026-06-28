<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\PlaceNotesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaceNotesService::class)]
final class PlaceNotesServiceTest extends TestCase
{
    private PlaceNotesService $service;

    protected function setUp(): void
    {
        $this->service = new PlaceNotesService();
    }

    public function testRenderEmptyReturnsEmpty(): void
    {
        self::assertSame('', $this->service->render(''));
        self::assertSame('', $this->service->render('   '));
    }

    public function testRenderBasicMarkdown(): void
    {
        $out = $this->service->render('# Hallo');
        self::assertStringContainsString('<h1>Hallo</h1>', $out);
    }

    public function testRenderGfmTaskListInstrumented(): void
    {
        $out = $this->service->render("- [ ] todo\n- [x] done");
        // Checkboxen sind nicht disabled und haben Task-Index
        self::assertStringContainsString('data-task-index="0"', $out);
        self::assertStringContainsString('data-task-index="1"', $out);
        self::assertStringNotContainsString('disabled', $out);
    }

    public function testRenderPriorityHighBadge(): void
    {
        $out = $this->service->render("- [ ] !! Wichtige Aufgabe");
        self::assertStringContainsString('ortsregister-task-prio-high', $out);
        self::assertStringContainsString('Wichtige Aufgabe', $out);
    }

    public function testRenderPriorityMediumBadge(): void
    {
        $out = $this->service->render("- [ ] ! Mittlere Aufgabe");
        self::assertStringContainsString('ortsregister-task-prio-med', $out);
        self::assertStringNotContainsString('ortsregister-task-prio-high', $out);
    }

    public function testRenderDateBadgeFullDate(): void
    {
        $out = $this->service->render("- [ ] @2026-07-15 KB Taufen prüfen");
        self::assertStringContainsString('ortsregister-task-date', $out);
        self::assertStringContainsString('2026-07-15', $out);
        self::assertStringContainsString('📅', $out);
    }

    public function testRenderDateBadgeYearOnly(): void
    {
        $out = $this->service->render("- [ ] @2026 irgendwas");
        self::assertStringContainsString('ortsregister-task-date', $out);
        self::assertStringContainsString('2026', $out);
    }

    public function testRenderPrioAndDateCombined(): void
    {
        $out = $this->service->render("- [ ] !! @2026-08 Wichtige Aufgabe");
        self::assertStringContainsString('ortsregister-task-prio-high', $out);
        self::assertStringContainsString('ortsregister-task-date', $out);
    }

    public function testRenderNoPrioWithoutCheckbox(): void
    {
        $out = $this->service->render("- !! kein Task, nur Liste");
        // Ohne Checkbox kein Prio-Badge
        self::assertStringNotContainsString('ortsregister-task-prio', $out);
    }

    public function testIsValidFilenameAccepts(): void
    {
        self::assertTrue($this->service->isValidFilename('notes.md'));
        self::assertTrue($this->service->isValidFilename('recherche.md'));
        self::assertTrue($this->service->isValidFilename('a-b_c.md'));
    }

    public function testIsValidFilenameRejects(): void
    {
        self::assertFalse($this->service->isValidFilename(''));
        self::assertFalse($this->service->isValidFilename('Notes.md'));    // Großbuchstabe vorne
        self::assertFalse($this->service->isValidFilename('.hidden.md'));
        self::assertFalse($this->service->isValidFilename('foo'));         // keine Endung
        self::assertFalse($this->service->isValidFilename('foo.txt'));     // nicht .md
        self::assertFalse($this->service->isValidFilename('../etc.md'));
        self::assertFalse($this->service->isValidFilename('sub/file.md'));
    }

    public function testToggleTaskInMarkdownChecks(): void
    {
        $md  = "- [ ] erstes\n- [ ] zweites";
        $out = $this->service->toggleTaskInMarkdown($md, 1, true);
        self::assertStringContainsString("- [ ] erstes", $out);
        self::assertStringContainsString("- [x] zweites", $out);
    }

    public function testToggleTaskInMarkdownUnchecks(): void
    {
        $md  = "- [x] done";
        $out = $this->service->toggleTaskInMarkdown($md, 0, false);
        self::assertStringContainsString("- [ ] done", $out);
    }

    public function testToggleTaskIgnoresInvalidIndex(): void
    {
        $md  = "- [ ] only one";
        $out = $this->service->toggleTaskInMarkdown($md, 5, true);
        // Index nicht vorhanden — Markdown bleibt
        self::assertSame($md, $out);
    }
}
