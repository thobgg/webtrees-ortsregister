<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Fisharebest\Webtrees\DB;
use RuntimeException;

/**
 * Kleine gemeinsame Naht für „schreibende Ortsoperationen sichern + rückgängig
 * machen": JSON-Snapshot als Datei + Log-Zeile in `ortsregister_merge_log`
 * (die Tabelle ist bewusst generisch: `operation`-Spalte, `src_place_id`,
 * `backup_path`).
 *
 * Bewusst NICHT in `PlaceOperationService` hineinrefaktoriert — dessen Merge/
 * Rename-Backup ist getestet und live-kritisch; diese Naht bedient zunächst nur
 * den neuen `LocationWriter`. Spätere Konsolidierung von Merge/Rename hierauf ist
 * ein separater, testabgesicherter Schritt.
 *
 * `date()` erzeugt den Dateinamen — im echten Request unkritisch (keine
 * Workflow-Sandbox).
 */
final class OperationBackup
{
    public function __construct(
        private readonly string $backupDir,
    ) {}

    /**
     * Snapshot als Datei ablegen. Liefert den absoluten Pfad.
     *
     * @param array<string, mixed> $payload
     */
    public function write(string $label, array $payload): string
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0775, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException('Backup-Verzeichnis nicht anlegbar: ' . $this->backupDir);
        }
        $safe  = substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $label), 0, 40);
        $fname = sprintf('%s/%s_%s.json', rtrim($this->backupDir, '/'), date('Y-m-d_His'), $safe);
        $ok    = file_put_contents(
            $fname,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
        if ($ok === false) {
            throw new RuntimeException('Backup konnte nicht geschrieben werden: ' . $fname);
        }
        return $fname;
    }

    /** @return array<string, mixed> */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Backup-Datei nicht gefunden: ' . $path);
        }
        $raw = (string) file_get_contents($path);
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Backup-Datei unlesbar: ' . $path);
        }
        return $data;
    }

    /**
     * Log-Zeile schreiben. Liefert die log_id (für spätere Undo-Anfrage).
     */
    public function log(int $treeId, string $operation, ?int $srcPlaceId, ?int $userId, string $backupPath): int
    {
        return (int) DB::table('ortsregister_merge_log')->insertGetId([
            'tree_id'      => $treeId,
            'operation'    => $operation,
            'src_place_id' => $srcPlaceId,
            'dst_place_id' => null,
            'user_id'      => $userId,
            'backup_path'  => $backupPath,
            'status'       => 'completed',
        ]);
    }

    /**
     * Backup-Pfad einer noch nicht rückgängig gemachten Operation holen.
     * Bindet an tree_id + operation, damit fremde/andere Ops nicht undo-bar sind.
     */
    public function backupPathForUndo(int $logId, int $treeId, string $operation): ?string
    {
        $row = DB::table('ortsregister_merge_log')
            ->where('id', '=', $logId)
            ->where('tree_id', '=', $treeId)
            ->where('operation', '=', $operation)
            ->where('status', '=', 'completed')
            ->select(['backup_path'])
            ->first();
        return $row !== null ? (string) $row->backup_path : null;
    }

    /**
     * log_id der jüngsten noch rückgängig-machbaren Operation eines Orts
     * (für den Undo-Button auf der Ortsseite). null = nichts rückgängig zu machen.
     */
    public function latestUndoable(int $treeId, string $operation, int $srcPlaceId): ?int
    {
        $row = DB::table('ortsregister_merge_log')
            ->where('tree_id', '=', $treeId)
            ->where('operation', '=', $operation)
            ->where('src_place_id', '=', $srcPlaceId)
            ->where('status', '=', 'completed')
            ->orderByDesc('id')
            ->select(['id'])
            ->first();
        return $row !== null ? (int) $row->id : null;
    }

    public function markUndone(int $logId): void
    {
        DB::table('ortsregister_merge_log')->where('id', '=', $logId)->update(['status' => 'undone']);
    }
}
