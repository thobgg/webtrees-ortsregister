<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Dto\LocationIdentity;
use Ortsregister\Dto\LocWritePlan;
use Ortsregister\Service\LocationReader;
use Ortsregister\Service\LocationWriter;
use Ortsregister\Service\OperationBackup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocationWriter::class)]
#[CoversClass(LocWritePlan::class)]
final class LocationWriterTest extends TestCase
{
    private LocationWriter $writer;

    protected function setUp(): void
    {
        // computePlan ist rein — Deps werden nicht berührt (keine DB/Tree).
        $this->writer = new LocationWriter(new LocationReader(), new OperationBackup('/tmp/ortsregister-test'));
    }

    /** @param list<LocationIdentity> $existing */
    private function plan(?string $gov, ?float $lat, ?float $lon, array $existing, string $leaf = 'Weiler'): LocWritePlan
    {
        return $this->writer->computePlan(1, $leaf, $gov, $lat, $lon, $existing);
    }

    public function testCreateWhenNoExisting(): void
    {
        $p = $this->plan('WEILER_W1', 49.1, 9.2, []);

        self::assertSame(LocWritePlan::ACTION_CREATE, $p->action);
        self::assertNull($p->targetXref);
        self::assertSame(
            ['1 NAME Weiler', '1 _GOV WEILER_W1', "1 MAP\n2 LATI N49.1\n2 LONG E9.2"],
            $p->facts,
        );
        self::assertTrue($p->willWrite());
        self::assertStringStartsWith("0 @@ _LOC\n1 NAME Weiler", $p->gedcomPreview());
    }

    public function testNoNakedLocWhenNoGovNoCoords(): void
    {
        // Entscheidung (a): ohne GOV UND ohne Koordinaten nichts anlegen.
        $p = $this->plan(null, null, null, []);
        self::assertSame(LocWritePlan::ACTION_NONE, $p->action);
        self::assertSame([], $p->facts);
        self::assertNull($p->targetXref);
        self::assertFalse($p->willWrite());
    }

    public function testCreateWithCoordsOnly(): void
    {
        // GOV fehlt, aber Koordinaten da → anlegen (Name + MAP).
        $p = $this->plan(null, 49.1, 9.2, []);
        self::assertSame(LocWritePlan::ACTION_CREATE, $p->action);
        self::assertSame(['1 NAME Weiler', "1 MAP\n2 LATI N49.1\n2 LONG E9.2"], $p->facts);
    }

    public function testUpdateFillsMissingGovAndCoords(): void
    {
        $existing = new LocationIdentity('L1', ['Weiler']); // nur Name
        $p = $this->plan('GOV_X', 48.844, 9.7189, [$existing]);

        self::assertSame(LocWritePlan::ACTION_UPDATE, $p->action);
        self::assertSame('L1', $p->targetXref);
        self::assertSame(['1 _GOV GOV_X', "1 MAP\n2 LATI N48.844\n2 LONG E9.7189"], $p->facts);
        // Update-Preview trägt KEINE 0-Zeile.
        self::assertStringStartsWith('1 _GOV GOV_X', $p->gedcomPreview());
    }

    public function testUpdateSkipsGovThatAlreadyMatches(): void
    {
        $existing = new LocationIdentity('L1', ['Weiler'], 'GOV_X'); // GOV schon da
        $p = $this->plan('GOV_X', 48.844, 9.7189, [$existing]);

        self::assertSame(LocWritePlan::ACTION_UPDATE, $p->action);
        self::assertSame(["1 MAP\n2 LATI N48.844\n2 LONG E9.7189"], $p->facts);
        self::assertSame([], $p->conflicts);
    }

    public function testGovConflictNeverOverwrites(): void
    {
        $existing = new LocationIdentity('L1', ['Weiler'], 'GOV_ALT'); // anderer GOV
        $p = $this->plan('GOV_NEU', null, null, [$existing]);

        self::assertSame(LocWritePlan::ACTION_NONE, $p->action); // nichts zu schreiben
        self::assertSame([], $p->facts);
        self::assertCount(1, $p->conflicts);
        self::assertStringContainsString('GOV weicht ab', $p->conflicts[0]);
        self::assertFalse($p->willWrite());
    }

    public function testCoordConflictNeverOverwrites(): void
    {
        $existing = new LocationIdentity('L1', ['Weiler'], null, 48.900, 9.7189); // andere Koord
        $p = $this->plan(null, 48.844, 9.7189, [$existing]);

        self::assertSame(LocWritePlan::ACTION_NONE, $p->action);
        self::assertCount(1, $p->conflicts);
        self::assertStringContainsString('Koordinaten weichen ab', $p->conflicts[0]);
    }

    public function testNoCoordConflictWhenRoundEqual(): void
    {
        // Bestand 48.844000, Wunsch 48.8440 → gleicher 5-Dezimal-String → kein Konflikt, nichts zu tun.
        $existing = new LocationIdentity('L1', ['Weiler'], 'G', 48.844000, 9.7189);
        $p = $this->plan('G', 48.8440, 9.7189, [$existing]);

        self::assertSame(LocWritePlan::ACTION_NONE, $p->action);
        self::assertSame([], $p->conflicts);
        self::assertSame([], $p->facts);
    }

    public function testAmbiguousWhenMultipleExisting(): void
    {
        $p = $this->plan('G', 1.0, 2.0, [
            new LocationIdentity('L1', ['Weiler']),
            new LocationIdentity('L2', ['Weiler']),
        ]);

        self::assertSame(LocWritePlan::ACTION_AMBIGUOUS, $p->action);
        self::assertSame([['xref' => 'L1', 'name' => 'Weiler'], ['xref' => 'L2', 'name' => 'Weiler']], $p->candidates);
        self::assertFalse($p->willWrite());
    }

    public function testSouthWestHemisphereFormat(): void
    {
        $p = $this->plan(null, -54.8019, -68.3030, []);
        self::assertSame(['1 NAME Weiler', "1 MAP\n2 LATI S54.8019\n2 LONG W68.303"], $p->facts);
    }

    public function testCoordinatesRoundedToFiveDecimals(): void
    {
        // round() liefert Float → nachlaufende Nullen fallen beim String-Cast weg.
        $p = $this->plan(null, 48.8440123, 9.7189987, []);
        self::assertSame("1 MAP\n2 LATI N48.84401\n2 LONG E9.719", $p->facts[1]);
    }
}
