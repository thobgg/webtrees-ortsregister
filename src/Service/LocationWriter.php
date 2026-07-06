<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\LocationIdentity;
use Ortsregister\Dto\LocWritePlan;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use RuntimeException;

/**
 * W1 der `_LOC`-Identitäts-Schicht: schreibt/aktualisiert EINEN `_LOC`-Record
 * pro Ort — additiv, gap-fill-only, opt-in. Berührt NUR den `_LOC`-Record,
 * KEINE INDI/FAM (Ereignis-Verknüpfung `2 _LOC @x@` wäre W2).
 *
 * Schreibweg = nativ wie Core `CreateLocationAction`: `$tree->createRecord()`
 * für neu, `$record->createFact()` fürs additive Ergänzen. Kein placelinks-
 * Handling nötig (ein `_LOC` lebt nur in `other`, wird nie orphan-bereinigt —
 * am Core verifiziert). Kein Core-Fork.
 *
 * Der Reader ist die Dedup-Schicht: existiert schon ein `_LOC` mit passendem
 * Namen, werden nur fehlende Felder ergänzt; abweichende Bestandswerte werden
 * NIE überschrieben, sondern als Konflikt gemeldet.
 *
 * `plan()`-Kern (`computePlan`) + `buildFacts`/Koordinaten-Format sind rein und
 * isoliert testbar; `execute()`/`undo()` rufen die native Core-API.
 */
final class LocationWriter
{
    public function __construct(
        private readonly LocationReader  $reader,
        private readonly OperationBackup $backup,
    ) {}

    // ---------------------------------------------------------------
    // Plan (was würde geschrieben) — Dedup über den Reader
    // ---------------------------------------------------------------

    public function plan(Tree $tree, int $placeId, string $leaf, ?string $govId, ?float $lat, ?float $lon): LocWritePlan
    {
        $existing = $this->reader->forPlaceName($tree, $leaf);
        return $this->computePlan($placeId, trim($leaf), $govId, $lat, $lon, $existing);
    }

    /**
     * Wie plan(), aber gegen einen ausdrücklich gewählten `_LOC` (Auflösung des
     * mehrdeutigen Falls: User hat einen Kandidaten gewählt).
     */
    public function planForTarget(Tree $tree, int $placeId, string $leaf, ?string $govId, ?float $lat, ?float $lon, string $targetXref): LocWritePlan
    {
        $id       = $this->reader->make($tree, $targetXref);
        $existing = $id !== null ? [$id] : [];
        return $this->computePlan($placeId, trim($leaf), $govId, $lat, $lon, $existing);
    }

    /**
     * Reiner Plan-Kern (keine DB/Tree) — nimmt das Reader-Ergebnis entgegen.
     *
     * @param list<LocationIdentity> $existing
     */
    public function computePlan(int $placeId, string $leaf, ?string $govId, ?float $lat, ?float $lon, array $existing): LocWritePlan
    {
        $govId    = $govId !== null && trim($govId) !== '' ? trim($govId) : null;
        $hasCoord = $lat !== null && $lon !== null;

        // Mehrere passende _LOC → nicht automatisch entscheiden.
        if (count($existing) > 1) {
            $candidates = array_map(
                static fn(LocationIdentity $e): array => ['xref' => $e->xref, 'name' => $e->primaryName()],
                $existing,
            );
            return new LocWritePlan(LocWritePlan::ACTION_AMBIGUOUS, $placeId, $leaf, null, [], [], array_values($candidates));
        }

        // Kein _LOC → neu anlegen (Name + vorhandene Identität).
        if ($existing === []) {
            // Entscheidung (a): ohne GOV UND ohne Koordinaten gibt es nichts zu
            // graduieren — keinen nackten Name-only-_LOC anlegen.
            if ($govId === null && !$hasCoord) {
                return new LocWritePlan(LocWritePlan::ACTION_NONE, $placeId, $leaf, null);
            }
            $facts = [$this->nameFact($leaf)];
            if ($govId !== null) {
                $facts[] = $this->govFact($govId);
            }
            if ($hasCoord) {
                $facts[] = $this->mapFact($lat, $lon);
            }
            return new LocWritePlan(LocWritePlan::ACTION_CREATE, $placeId, $leaf, null, $facts);
        }

        // Genau ein _LOC → additiv Lücken füllen, Konflikte melden (nie überschreiben).
        $e         = $existing[0];
        $facts     = [];
        $conflicts = [];

        if ($govId !== null) {
            if (!$e->hasGov()) {
                $facts[] = $this->govFact($govId);
            } elseif ($e->govId !== $govId) {
                $conflicts[] = sprintf('GOV weicht ab: _LOC trägt „%s", Ortsregister „%s" (nicht überschrieben).', $e->govId, $govId);
            }
        }

        if ($hasCoord) {
            if (!$e->hasCoordinates()) {
                $facts[] = $this->mapFact($lat, $lon);
            } else {
                $desired = $this->formatLat($lat) . ' / ' . $this->formatLon($lon);
                $current = $this->formatLat((float) $e->latitude) . ' / ' . $this->formatLon((float) $e->longitude);
                if ($desired !== $current) {
                    $conflicts[] = sprintf('Koordinaten weichen ab: _LOC %s, Ortsregister %s (nicht überschrieben).', $current, $desired);
                }
            }
        }

        $action = $facts !== [] ? LocWritePlan::ACTION_UPDATE : LocWritePlan::ACTION_NONE;
        return new LocWritePlan($action, $placeId, $leaf, $e->xref, $facts, $conflicts);
    }

    // ---------------------------------------------------------------
    // Ausführen (native Core-API) + Backup
    // ---------------------------------------------------------------

    /**
     * Führt den Plan aus. Liefert {action, xref, backup_path, written}.
     * Wirft, wenn Pending-Changes nicht auto-akzeptiert werden (wie Merge).
     *
     * @return array{action:string, xref:?string, backup_path:?string, written:bool}
     */
    public function execute(Tree $tree, LocWritePlan $plan): array
    {
        if (!$plan->willWrite()) {
            return ['action' => $plan->action, 'xref' => null, 'backup_path' => null, 'written' => false];
        }
        $this->assertAutoAccept();

        if ($plan->action === LocWritePlan::ACTION_CREATE) {
            $gedcom = "0 @@ _LOC\n" . implode("\n", $plan->facts);
            $record = $tree->createRecord($gedcom);
            $xref   = $record->xref();
            $payload = [
                'version'    => 1,
                'operation'  => 'loc_write',
                'action'     => 'create',
                'place_id'   => $plan->placeId,
                'place_name' => $plan->placeName,
                'xref'       => $xref,
            ];
        } else {
            $xref   = (string) $plan->targetXref;
            $record = Registry::locationFactory()->make($xref, $tree);
            if ($record === null) {
                throw new RuntimeException('_LOC @' . $xref . '@ nicht gefunden — bitte Seite neu laden.');
            }
            $payload = [
                'version'    => 1,
                'operation'  => 'loc_write',
                'action'     => 'update',
                'place_id'   => $plan->placeId,
                'place_name' => $plan->placeName,
                'xref'       => $xref,
                'pre_gedcom' => $record->gedcom(),
            ];
            foreach ($plan->facts as $fact) {
                $record->createFact($fact, true);
            }
        }

        $backupPath = $this->backup->write('loc_' . $plan->placeName, $payload);

        return ['action' => $plan->action, 'xref' => $xref, 'backup_path' => $backupPath, 'written' => true];
    }

    /**
     * Macht einen früheren `execute()` rückgängig.
     *   create → deleteRecord(); update → updateRecord(pre_gedcom).
     *
     * @return array{action:string, xref:?string, reverted:bool}
     */
    public function undo(Tree $tree, string $backupPath): array
    {
        $b      = $this->backup->read($backupPath);
        $action = (string) ($b['action'] ?? '');
        $xref   = (string) ($b['xref'] ?? '');

        $record = $xref !== '' ? Registry::locationFactory()->make($xref, $tree) : null;

        if ($action === 'create') {
            if ($record !== null) {
                $record->deleteRecord();
            }
            return ['action' => 'create', 'xref' => $xref, 'reverted' => $record !== null];
        }

        if ($action === 'update') {
            if ($record !== null) {
                $record->updateRecord((string) ($b['pre_gedcom'] ?? ''), false);
            }
            return ['action' => 'update', 'xref' => $xref, 'reverted' => $record !== null];
        }

        throw new RuntimeException('Unbekannte Backup-Aktion: ' . $action);
    }

    // ---------------------------------------------------------------
    // Reine Bausteine (isoliert testbar)
    // ---------------------------------------------------------------

    private function nameFact(string $leaf): string
    {
        // Blattnamen sind einzeilig; CONT-Behandlung wie Core zur Sicherheit.
        return '1 NAME ' . strtr(trim($leaf), ["\n" => "\n2 CONT "]);
    }

    private function govFact(string $govId): string
    {
        return '1 _GOV ' . trim($govId);
    }

    private function mapFact(float $lat, float $lon): string
    {
        return "1 MAP\n2 LATI " . $this->formatLat($lat) . "\n2 LONG " . $this->formatLon($lon);
    }

    /**
     * Format wie Core `MapDataService::writeDegrees` (round 5, Hemisphären-Präfix).
     * Präfixe als Literale (Core `Gedcom::LATITUDE_NORTH` etc.), damit die reine
     * Formatierung ohne Core-Klasse testbar bleibt — analog `LocationReader::parseGeo`.
     */
    private function formatLat(float $lat): string
    {
        return $this->formatDegrees($lat, 'N', 'S');
    }

    private function formatLon(float $lon): string
    {
        return $this->formatDegrees($lon, 'E', 'W');
    }

    private function formatDegrees(float $degrees, string $positive, string $negative): string
    {
        $degrees = round($degrees, 5);
        return $degrees < 0.0 ? $negative . abs($degrees) : $positive . $degrees;
    }

    private function assertAutoAccept(): void
    {
        if (Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) !== '1') {
            throw new RuntimeException(
                'Zum Schreiben von _LOC-Records muss in deinen Kontoeinstellungen '
                . '„Änderungen automatisch übernehmen" aktiv sein — sonst bliebe der Record in der Moderation hängen.'
            );
        }
    }
}
