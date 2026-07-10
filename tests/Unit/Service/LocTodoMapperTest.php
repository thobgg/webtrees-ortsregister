<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Dto\PlaceTask;
use Ortsregister\Service\LocTodoMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Grammatik-Kern von #7 (Orts-Aufgaben als `_LOC:_TODO`): Serialisieren, Parsen,
 * Ersetzen — der Round-Trip muss verlustfrei sein, und `setTasks()` darf am
 * restlichen Record kein Byte anfassen. DB-frei.
 */
#[CoversClass(LocTodoMapper::class)]
final class LocTodoMapperTest extends TestCase
{
    private LocTodoMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LocTodoMapper();
    }

    private function task(string $text, string $status = PlaceTask::STATUS_OPEN, string $created = '', string $author = '', string $id = 'abc123def456'): PlaceTask
    {
        return new PlaceTask(id: $id, text: $text, status: $status, created: $created, author: $author);
    }

    // ---------- Serialisieren ----------

    public function testFullTaskSerialisation(): void
    {
        $fact = $this->mapper->taskToFact($this->task(
            'KB Musterdorf 1720-1750 durchsehen',
            PlaceTask::STATUS_DONE,
            '2026-07-10',
            'thomas',
        ));

        self::assertSame(
            "1 _TODO KB Musterdorf 1720-1750 durchsehen\n"
            . "2 DATE 10 JUL 2026\n"
            . "2 _WT_USER thomas\n"
            . "2 STAT completed\n"
            . '2 _UID abc123def456',
            $fact,
        );
    }

    public function testMinimalOpenTaskOmitsEmptyFields(): void
    {
        $fact = $this->mapper->taskToFact($this->task('Heiraten prüfen'));

        self::assertSame("1 _TODO Heiraten prüfen\n2 _UID abc123def456", $fact);
        self::assertStringNotContainsString('STAT', $fact);
        self::assertStringNotContainsString('DATE', $fact);
    }

    // ---------- Round-Trip ----------

    public function testRoundTripPreservesEverything(): void
    {
        $original = [
            $this->task('Taufen 1700-1750', PlaceTask::STATUS_DONE, '2026-06-28', 'thomas', 'aaa111bbb222'),
            $this->task("Beerdigungen prüfen\nauch Nachbardorf", PlaceTask::STATUS_OPEN, '2026-07-10', 'hermann', 'ccc333ddd444'),
        ];
        $gedcom = $this->mapper->setTasks("0 @L1@ _LOC\n1 NAME Weiler\n1 _GOV WEILER_W1", $original);
        $back   = $this->mapper->tasksFromRecord($gedcom);

        self::assertCount(2, $back);
        foreach ($original as $k => $o) {
            self::assertSame($o->text,    $back[$k]->text);
            self::assertSame($o->status,  $back[$k]->status);
            self::assertSame($o->created, $back[$k]->created);
            self::assertSame($o->author,  $back[$k]->author);
            self::assertSame($o->id,      $back[$k]->id);
        }
    }

    public function testSetTasksTouchesNothingElse(): void
    {
        $record = "0 @L1@ _LOC\n1 NAME Weiler\n1 _GOV WEILER_W1\n1 MAP\n2 LATI N49.1\n2 LONG E9.1\n1 NOTE Beschreibung\n2 CONT zweite Zeile";
        $out    = $this->mapper->setTasks($record, [$this->task('Neu')]);

        self::assertStringContainsString($record, $out); // Rest byte-identisch, _TODO hinten dran
        self::assertStringContainsString('1 _TODO Neu', $out);
    }

    public function testSetTasksReplacesExistingTodos(): void
    {
        $record = "0 @L1@ _LOC\n1 NAME Weiler\n1 _TODO alt\n2 _UID x\n1 _GOV WEILER_W1";
        $out    = $this->mapper->setTasks($record, [$this->task('neu')]);

        self::assertStringNotContainsString('alt', $out);
        self::assertStringContainsString('1 _TODO neu', $out);
        self::assertStringContainsString('1 _GOV WEILER_W1', $out); // Zwischenliegendes bleibt
    }

    public function testEmptyListRemovesAllTodos(): void
    {
        $record = "0 @L1@ _LOC\n1 NAME Weiler\n1 _TODO a\n2 STAT completed\n1 _TODO b";
        $out    = $this->mapper->setTasks($record, []);

        self::assertStringNotContainsString('_TODO', $out);
        self::assertStringContainsString('1 NAME Weiler', $out);
    }

    public function testParserToleratesForeignSubtagsAndMissingUid(): void
    {
        // Fremde Subtags (NOTE, REPO) überlesen; ohne _UID → stabile Ersatz-ID.
        $record = "0 @L1@ _LOC\n1 _TODO Archivbesuch\n2 DATE 1 JAN 2020\n2 NOTE von Hand ergänzt\n2 REPO @R1@";
        $tasks  = $this->mapper->tasksFromRecord($record);

        self::assertCount(1, $tasks);
        self::assertSame('Archivbesuch', $tasks[0]->text);
        self::assertSame('2020-01-01', $tasks[0]->created);
        self::assertNotSame('', $tasks[0]->id);
    }

    // ---------- Datum ----------

    public function testDateConversionBothWays(): void
    {
        self::assertSame('10 JUL 2026', $this->mapper->gedcomDate('2026-07-10'));
        self::assertSame('1 JAN 2020',  $this->mapper->gedcomDate('2020-01-01'));
        self::assertSame('', $this->mapper->gedcomDate(''));
        self::assertSame('', $this->mapper->gedcomDate('kein datum'));
        self::assertSame('', $this->mapper->gedcomDate('2026-13-01'));

        self::assertSame('2026-07-10', $this->mapper->isoDate('10 JUL 2026'));
        self::assertSame('', $this->mapper->isoDate('ABT 1850'));      // unscharf → leer, nicht raten
        self::assertSame('', $this->mapper->isoDate(''));
    }
}
