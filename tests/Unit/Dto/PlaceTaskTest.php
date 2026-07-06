<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Dto;

use Ortsregister\Dto\PlaceTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaceTask::class)]
final class PlaceTaskTest extends TestCase
{
    public function testDefaultsHaveEmptyCreatedAndAuthor(): void
    {
        $t = new PlaceTask('abc', 'Friedhof besuchen');
        self::assertSame(PlaceTask::STATUS_OPEN, $t->status);
        self::assertSame('', $t->created);
        self::assertSame('', $t->author);
    }

    public function testToArrayOmitsEmptyMetadata(): void
    {
        $t = new PlaceTask('abc', 'Aufgabe');
        self::assertSame(['id' => 'abc', 'text' => 'Aufgabe', 'status' => 'open'], $t->toArray());
    }

    public function testToArrayIncludesMetadataWhenSet(): void
    {
        $t = new PlaceTask('abc', 'Aufgabe', PlaceTask::STATUS_OPEN, '2026-07-06', 'Thomas Bugge');
        self::assertSame([
            'id'      => 'abc',
            'text'    => 'Aufgabe',
            'status'  => 'open',
            'created' => '2026-07-06',
            'author'  => 'Thomas Bugge',
        ], $t->toArray());
    }

    public function testFromArrayReadsLegacyFileWithoutMetadata(): void
    {
        $t = PlaceTask::fromArray(['id' => 'x', 'text' => 'alt', 'status' => 'done']);
        self::assertSame('done', $t->status);
        self::assertSame('', $t->created);
        self::assertSame('', $t->author);
    }

    public function testFromArrayRoundTrip(): void
    {
        $raw = ['id' => 'x', 'text' => 'y', 'status' => 'open', 'created' => '2026-01-02', 'author' => 'Hermann'];
        self::assertSame($raw, PlaceTask::fromArray($raw)->toArray());
    }

    public function testToggledPreservesMetadata(): void
    {
        $t = (new PlaceTask('x', 'y', PlaceTask::STATUS_OPEN, '2026-01-02', 'Thomas'))->toggled();
        self::assertSame(PlaceTask::STATUS_DONE, $t->status);
        self::assertSame('2026-01-02', $t->created);
        self::assertSame('Thomas', $t->author);
    }

    public function testWithTextPreservesMetadata(): void
    {
        $t = (new PlaceTask('x', 'y', PlaceTask::STATUS_DONE, '2026-01-02', 'Thomas'))->withText('neu');
        self::assertSame('neu', $t->text);
        self::assertSame(PlaceTask::STATUS_DONE, $t->status);
        self::assertSame('2026-01-02', $t->created);
        self::assertSame('Thomas', $t->author);
    }
}
