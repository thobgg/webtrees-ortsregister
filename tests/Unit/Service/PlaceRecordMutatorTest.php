<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\GedcomPlaceManipulator;
use Ortsregister\Service\PlaceRecordMutator;
use Ortsregister\Service\RecordStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integrationstest des Merge-Sicherheitsnetzes auf Datensatz-Ebene — DB-frei
 * über einen In-Memory-RecordStore. Deckt genau die drei Garantien ab, die in
 * Produktion sonst nirgends automatisiert geprüft sind:
 *   1. Rollback: schlägt ein Schreibvorgang mitten im Merge fehl, bleibt (in
 *      einer Transaktion) KEIN Teilzustand zurück.
 *   2. Undo: der Restore stellt den Vor-Merge-Stand byte-identisch wieder her.
 *   3. Stale-Schutz: wurde ein Record seit dem Merge fremd verändert, meldet die
 *      Erkennung ihn — der Aufruf bricht All-or-Nothing ab (kein Teil-Revert).
 */
#[CoversClass(PlaceRecordMutator::class)]
final class PlaceRecordMutatorTest extends TestCase
{
    private const G1 = "0 @I1@ INDI\n1 BIRT\n2 PLAC Hambvurg\n1 SEX M";
    private const G2 = "0 @I2@ INDI\n1 DEAT\n2 PLAC Hambvurg";
    private const G3 = "0 @I3@ INDI\n1 BIRT\n2 PLAC Hambvurg\n1 SEX F";
    // Kontroll-Record mit anderem Ort — vom Merge unberührt.
    private const G4 = "0 @I4@ INDI\n1 BIRT\n2 PLAC Bremen";

    private PlaceRecordMutator $mutator;

    protected function setUp(): void
    {
        $this->mutator = new PlaceRecordMutator(new GedcomPlaceManipulator());
    }

    /** @return list<array{xref: string, type: string}> */
    private function affected(string ...$xrefs): array
    {
        return array_map(static fn (string $x): array => ['xref' => $x, 'type' => 'INDI'], $xrefs);
    }

    // ---------------------------------------------------------------
    // applyMerge: transformiert nur wirklich betroffene Records
    // ---------------------------------------------------------------

    public function testApplyMergeTransformsOnlyAffectedRecords(): void
    {
        $store = new InMemoryRecordStore([
            'I1' => self::G1, 'I2' => self::G2, 'I3' => self::G3, 'I4' => self::G4,
        ]);

        $after = $this->mutator->applyMerge(
            $store,
            $this->affected('I1', 'I2', 'I3', 'I4'),
            'Hambvurg',
            'Hamburg',
            [],
            [],
        );

        // Nur die drei „Hambvurg"-Records sind im afterMap und wurden geschrieben.
        self::assertSame(['I1', 'I2', 'I3'], array_keys($after));
        self::assertSame(['I1', 'I2', 'I3'], $store->writes);

        self::assertStringContainsString('2 PLAC Hamburg', (string) $store->get('I1'));
        self::assertStringContainsString('2 PLAC Hamburg', (string) $store->get('I2'));
        self::assertStringContainsString('2 PLAC Hamburg', (string) $store->get('I3'));
        // Der Kontroll-Record bleibt byte-identisch (kein unnötiger Schreibvorgang).
        self::assertSame(self::G4, $store->get('I4'));
    }

    // ---------------------------------------------------------------
    // Undo: byte-identische Wiederherstellung
    // ---------------------------------------------------------------

    public function testUndoRestoresPreMergeStateByteIdentical(): void
    {
        $originals = ['I1' => self::G1, 'I2' => self::G2, 'I3' => self::G3, 'I4' => self::G4];
        $store     = new InMemoryRecordStore($originals);

        $after = $this->mutator->applyMerge(
            $store,
            $this->affected('I1', 'I2', 'I3', 'I4'),
            'Hambvurg',
            'Hamburg',
            [],
            [],
        );
        // Zwischenstand: wirklich verändert.
        self::assertNotSame($originals, $store->all());

        $restored = $this->mutator->restore($store, $this->backupFrom($originals, $after));

        self::assertSame(4, $restored);
        self::assertSame($originals, $store->all(), 'Undo muss den Vor-Merge-Stand exakt herstellen');
    }

    // ---------------------------------------------------------------
    // Rollback: Fehler mitten im Merge lässt (mit Transaktion) nichts zurück
    // ---------------------------------------------------------------

    public function testFailedWriteMidMergePropagatesAndIsNotSwallowed(): void
    {
        // Ohne Transaktions-Netz: der Mutator schluckt den Fehler NICHT — er wirft,
        // und der bereits geschriebene I1 zeigt den gefährlichen Teilzustand.
        $store = new InMemoryRecordStore(
            ['I1' => self::G1, 'I2' => self::G2, 'I3' => self::G3],
            failOn: ['I2'],
        );

        $this->expectException(RuntimeException::class);
        try {
            $this->mutator->applyMerge(
                $store,
                $this->affected('I1', 'I2', 'I3'),
                'Hambvurg',
                'Hamburg',
                [],
                [],
            );
        } finally {
            self::assertStringContainsString('2 PLAC Hamburg', (string) $store->get('I1'));
            self::assertSame(self::G3, $store->get('I3'), 'nach dem Fehler nicht mehr erreicht');
        }
    }

    public function testTransactionRollbackLeavesStoreUnchangedOnMidMergeFailure(): void
    {
        $originals = ['I1' => self::G1, 'I2' => self::G2, 'I3' => self::G3];
        $store     = new InMemoryRecordStore($originals, failOn: ['I2']);

        $threw = false;
        try {
            // inTransaction() modelliert DB::connection()->transaction():
            // Snapshot vor Lauf, Restore + Rethrow bei Exception.
            $this->inTransaction($store, function () use ($store): void {
                $this->mutator->applyMerge(
                    $store,
                    $this->affected('I1', 'I2', 'I3'),
                    'Hambvurg',
                    'Hamburg',
                    [],
                    [],
                );
            });
        } catch (RuntimeException) {
            $threw = true;
        }

        self::assertTrue($threw, 'Fehler muss aus der Transaktion herauspropagieren');
        self::assertSame($originals, $store->all(), 'kein Teil-Merge nach Rollback');
    }

    // ---------------------------------------------------------------
    // Stale-Schutz: fremd geänderter Record → All-or-Nothing-Abbruch
    // ---------------------------------------------------------------

    public function testStaleDetectionFlagsForeignEditAndBlocksPartialRevert(): void
    {
        $originals = ['I1' => self::G1, 'I2' => self::G2, 'I3' => self::G3];
        $store     = new InMemoryRecordStore($originals);

        $after  = $this->mutator->applyMerge(
            $store,
            $this->affected('I1', 'I2', 'I3'),
            'Hambvurg',
            'Hamburg',
            [],
            [],
        );
        $backup = $this->backupFrom($originals, $after);

        // Nach dem Merge ändert jemand I2 (fremder Edit).
        $store->forceSet('I2', (string) $store->get('I2') . "\n1 NOTE spätere fremde Änderung");

        $changed = $this->mutator->detectStale($store, $backup);
        self::assertSame(['I2'], $changed, 'nur der fremd geänderte Record wird gemeldet');

        // Contract: changed !== []  ⇒  KEIN restore().  Nichts wird zurückgesetzt.
        self::assertStringContainsString('2 PLAC Hamburg', (string) $store->get('I1'), 'I1 bleibt gemergt (kein Teil-Revert)');
        self::assertStringContainsString('2 PLAC Hamburg', (string) $store->get('I3'), 'I3 bleibt gemergt (kein Teil-Revert)');
        self::assertStringContainsString('fremde Änderung', (string) $store->get('I2'), 'fremder Edit bleibt unangetastet');
    }

    public function testStaleDetectionIgnoresUnchangedRecords(): void
    {
        $originals = ['I1' => self::G1, 'I2' => self::G2];
        $store     = new InMemoryRecordStore($originals);

        $after  = $this->mutator->applyMerge($store, $this->affected('I1', 'I2'), 'Hambvurg', 'Hamburg', [], []);
        $backup = $this->backupFrom($originals, $after);

        // Kein fremder Edit → nichts ist „stale".
        self::assertSame([], $this->mutator->detectStale($store, $backup));
    }

    // ---------------------------------------------------------------
    // Helfer
    // ---------------------------------------------------------------

    /**
     * Baut die Backup-Sektion wie PlaceOperationService: before_gedcom für ALLE
     * betroffenen Records, after_gedcom nur für tatsächlich geänderte.
     *
     * @param array<string, string> $originals xref → Vor-Merge-GEDCOM
     * @param array<string, string> $after     xref → Nach-Merge-GEDCOM (nur geänderte)
     * @return list<array{xref: string, type: string, before_gedcom: string, after_gedcom?: string}>
     */
    private function backupFrom(array $originals, array $after): array
    {
        $section = [];
        foreach ($originals as $xref => $before) {
            $entry = ['xref' => $xref, 'type' => 'INDI', 'before_gedcom' => $before];
            if (isset($after[$xref])) {
                $entry['after_gedcom'] = $after[$xref];
            }
            $section[] = $entry;
        }
        return $section;
    }

    /**
     * Modelliert DB::connection()->transaction(): führt $fn aus; wirft es, wird
     * der Datensatz-Stand auf den Snapshot vor dem Lauf zurückgesetzt und die
     * Exception weitergereicht (wie der DB-Rollback der Record-Writes).
     */
    private function inTransaction(InMemoryRecordStore $store, callable $fn): void
    {
        $snapshot = $store->all();
        try {
            $fn();
        } catch (\Throwable $e) {
            $store->replaceAll($snapshot);
            throw $e;
        }
    }
}

/**
 * DB-freier {@see RecordStore} für den Test. Optional wirft write() für
 * bestimmte xrefs, um einen Fehlschlag mitten im Merge zu erzwingen.
 */
final class InMemoryRecordStore implements RecordStore
{
    /** @var array<string, string> */
    private array $data;

    /** @var list<string> */
    private array $failOn;

    /** @var list<string> Reihenfolge tatsächlich geschriebener xrefs */
    public array $writes = [];

    /**
     * @param array<string, string> $data
     * @param list<string>          $failOn
     */
    public function __construct(array $data, array $failOn = [])
    {
        $this->data   = $data;
        $this->failOn = $failOn;
    }

    public function read(string $xref, string $type): ?string
    {
        return $this->data[$xref] ?? null;
    }

    public function write(string $xref, string $type, string $gedcom): void
    {
        if (in_array($xref, $this->failOn, true)) {
            throw new RuntimeException('simulierter Schreibfehler: ' . $xref);
        }
        $this->data[$xref] = $gedcom;
        $this->writes[]    = $xref;
    }

    public function get(string $xref): ?string
    {
        return $this->data[$xref] ?? null;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->data;
    }

    /** @param array<string, string> $data */
    public function replaceAll(array $data): void
    {
        $this->data = $data;
    }

    /** Fremder Edit nach dem Merge (umgeht das writes-Log). */
    public function forceSet(string $xref, string $gedcom): void
    {
        $this->data[$xref] = $gedcom;
    }
}
