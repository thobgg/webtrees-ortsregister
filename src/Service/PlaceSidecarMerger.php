<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;
use JsonException;

/**
 * Vereinigt die kuratorische Sidecar-Schicht (DB `ortsregister_place_meta` +
 * Ordner `media/<root>/<blattname>/`) beim Merge zweier Orte — die Schicht, die
 * der reine GEDCOM-Merge nicht kennt und früher still verwaisen ließ.
 *
 * Strategie GATE v1 — sicher + voll reversibel, bewusst OHNE Inhalts-Merge:
 *  - DB: meta_data per array_replace_recursive vereinigen (Ziel gewinnt bei
 *    Skalar-Konflikt), gov_id Ziel-gewinnt + Warnung bei Divergenz, Quell-Row
 *    löschen. Behebt den PK-Duplicate-Crash des alten Einzeilers.
 *  - Ordner: Ziel fehlt → Verzeichnis umbenennen (billig, verlustfrei); beide
 *    da → jede Quell-Datei ins Ziel verschieben, bei Namenskollision mit Suffix
 *    "__von_<quelle>" (NIE überschreiben, NIE Inhalt mergen → null Datenverlust).
 *  - Bei mehrdeutigem Blattnamen (zwei Orte teilen einen Ordnernamen) wird der
 *    Ordner-Teil übersprungen + gewarnt; der DB-Teil läuft trotzdem, da place_id
 *    eindeutig ist.
 *
 * Inhalts-Merge (Notizen anhängen, _tasks/_kb konkatenieren) ist die bewusste
 * Phase-1.1-Verfeinerung — hier zählt zuerst Verlustfreiheit + Reversibilität.
 *
 * Jede Mutation wird in ein Manifest protokolliert; restore() macht sie exakt
 * rückgängig (für undoMerge / Backup-v2). Filesystem-Ops sind NICHT
 * transaktional (Härtungs-Punkt #6) — das Manifest erlaubt Recovery.
 */
final class PlaceSidecarMerger
{
    private const TABLE = 'ortsregister_place_meta';

    public function __construct(
        private readonly PlaceFolderLocator $locator,
    ) {}

    /**
     * Führt DB- + Ordner-Merge aus (mutiert), liefert Backup-Sektionen + Warnungen.
     *
     * @return array{warnings: list<string>, place_meta: array<string,mixed>, folders: array<string,mixed>}
     */
    public function apply(
        Tree   $tree,
        int    $srcId,
        int    $dstId,
        string $srcLeaf,
        string $dstLeaf,
        bool   $srcLeafAmbiguous,
        bool   $dstLeafAmbiguous,
    ): array {
        $warnings = [];

        $metaBackup   = $this->mergeMeta($tree, $srcId, $dstId, $warnings);
        $folderBackup = $this->mergeFolder($tree, $srcLeaf, $dstLeaf, $srcLeafAmbiguous, $dstLeafAmbiguous, $warnings);

        return [
            'warnings'   => $warnings,
            'place_meta' => $metaBackup,
            'folders'    => $folderBackup,
        ];
    }

    /**
     * Macht apply() exakt rückgängig. Tolerant gegenüber fehlenden Sektionen.
     *
     * @param array<string,mixed>|null $metaBackup
     * @param array<string,mixed>|null $folderBackup
     */
    public function restore(
        Tree   $tree,
        ?array $metaBackup,
        ?array $folderBackup,
        ?int   $srcIdNow = null,
        ?int   $dstIdNow = null,
    ): void {
        // Ordner zuerst (Dateien zurück an die Quelle), dann DB.
        if (is_array($folderBackup)) {
            $this->restoreFolder($folderBackup);
        }
        if (is_array($metaBackup)) {
            $this->restoreMeta($tree, $metaBackup, $srcIdNow, $dstIdNow);
        }
    }

    // ---------------------------------------------------------------
    // DB: ortsregister_place_meta
    // ---------------------------------------------------------------

    /**
     * @param list<string> $warnings
     * @return array<string,mixed>  Vorher-Snapshot für restore()
     */
    private function mergeMeta(Tree $tree, int $srcId, int $dstId, array &$warnings): array
    {
        $srcRow = $this->loadMetaRow($tree, $srcId);
        $dstRow = $this->loadMetaRow($tree, $dstId);

        $backup = [
            'tree_id'    => $tree->id(),
            'src_id'     => $srcId,
            'dst_id'     => $dstId,
            'src_before' => $srcRow,
            'dst_before' => $dstRow,
        ];

        if ($srcRow === null) {
            return $backup; // Quelle hat keine kuratorischen Daten → nichts zu tun
        }

        if ($dstRow === null) {
            // Ziel hat keine Zeile → Quell-Zeile einfach umhängen (kein PK-Konflikt)
            DB::table(self::TABLE)
                ->where('tree_id', '=', $tree->id())
                ->where('place_id', '=', $srcId)
                ->update(['place_id' => $dstId]);
            return $backup;
        }

        // Beide Zeilen vorhanden → vereinigen, Ziel gewinnt bei Konflikt.
        $merged = array_replace_recursive(
            $this->decodeMeta($srcRow['meta_data']),
            $this->decodeMeta($dstRow['meta_data']),
        );

        $srcGov = $srcRow['gov_id'];
        $dstGov = $dstRow['gov_id'];
        $govId  = $dstGov ?? $srcGov;
        if ($srcGov !== null && $dstGov !== null && $srcGov !== $dstGov) {
            $warnings[] = sprintf(
                'GOV-Konflikt: Quelle (%s) und Ziel (%s) sind verschieden verknüpft — Ziel behalten. '
                . 'Bitte prüfen, ob es wirklich derselbe Ort ist.',
                $srcGov,
                $dstGov,
            );
        }

        DB::table(self::TABLE)
            ->where('tree_id', '=', $tree->id())
            ->where('place_id', '=', $dstId)
            ->update([
                'gov_id'    => $govId,
                'meta_data' => $merged === [] ? null : $this->encodeMeta($merged),
            ]);

        DB::table(self::TABLE)
            ->where('tree_id', '=', $tree->id())
            ->where('place_id', '=', $srcId)
            ->delete();

        return $backup;
    }

    /**
     * @param array<string,mixed> $backup
     */
    private function restoreMeta(Tree $tree, array $backup, ?int $srcIdNow, ?int $dstIdNow): void
    {
        $treeId = (int) ($backup['tree_id'] ?? $tree->id());
        // Aktuelle place_id nach dem GEDCOM-Restore (Härtungspunkt #7: place_id
        // driftet nach Reindex). Fällt auf die Backup-IDs zurück, wenn die
        // Namens-Auflösung scheitert.
        $srcId = $srcIdNow ?? (int) ($backup['src_id'] ?? 0);
        $dstId = $dstIdNow ?? (int) ($backup['dst_id'] ?? 0);

        // Aktuelle Zeilen an src/dst entfernen, dann Vorher-Zustand neu setzen.
        foreach ([$srcId, $dstId] as $pid) {
            if ($pid > 0) {
                DB::table(self::TABLE)
                    ->where('tree_id', '=', $treeId)
                    ->where('place_id', '=', $pid)
                    ->delete();
            }
        }
        // src_before gehört an die (heute) Quell-ID, dst_before an die Ziel-ID.
        $this->reinsertMeta($treeId, $srcId, $backup['src_before'] ?? null);
        $this->reinsertMeta($treeId, $dstId, $backup['dst_before'] ?? null);
    }

    /**
     * @param array<string,mixed>|null $row
     */
    private function reinsertMeta(int $treeId, int $placeId, ?array $row): void
    {
        if ($placeId <= 0 || !is_array($row)) {
            return;
        }
        DB::table(self::TABLE)->insert([
            'tree_id'   => $treeId,
            'place_id'  => $placeId,
            'gov_id'    => $row['gov_id'] ?? null,
            'meta_data' => $row['meta_data'] ?? null,
        ]);
    }

    /**
     * @return array{place_id:int, gov_id:?string, meta_data:?string}|null
     */
    private function loadMetaRow(Tree $tree, int $placeId): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('tree_id', '=', $tree->id())
            ->where('place_id', '=', $placeId)
            ->first();
        if ($row === null) {
            return null;
        }
        return [
            'place_id'  => (int) $row->place_id,
            'gov_id'    => $row->gov_id !== null ? (string) $row->gov_id : null,
            'meta_data' => $row->meta_data !== null ? (string) $row->meta_data : null,
        ];
    }

    /**
     * @return array<mixed>
     */
    private function decodeMeta(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param array<mixed> $meta
     */
    private function encodeMeta(array $meta): string
    {
        return (string) json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    // ---------------------------------------------------------------
    // Ordner: media/<root>/<blattname>/
    // ---------------------------------------------------------------

    /**
     * @param list<string> $warnings
     * @return array<string,mixed>  Manifest für restore()
     */
    private function mergeFolder(
        Tree   $tree,
        string $srcLeaf,
        string $dstLeaf,
        bool   $srcAmbiguous,
        bool   $dstAmbiguous,
        array  &$warnings,
    ): array {
        if ($srcAmbiguous || $dstAmbiguous) {
            $warnings[] = sprintf(
                'Ordner-Zusammenführung übersprungen: der Blattname „%s" kommt mehrfach vor — '
                . 'eine eindeutige Ordner-Zuordnung ist nicht möglich. Bitte Dateien manuell prüfen.',
                $srcAmbiguous ? $srcLeaf : $dstLeaf,
            );
            return ['skipped' => true, 'ops' => []];
        }

        $srcDir = $this->locator->folder($tree, $srcLeaf);
        $dstDir = $this->locator->folder($tree, $dstLeaf);
        if ($srcDir === null || $dstDir === null || !is_dir($srcDir)) {
            return ['skipped' => true, 'ops' => []]; // unsicherer Name oder Quelle ohne Ordner
        }

        $ops = [];

        if (!is_dir($dstDir)) {
            // Ziel-Ordner fehlt → ganzes Verzeichnis umbenennen.
            $parent = dirname($dstDir);
            if (!is_dir($parent)) {
                @mkdir($parent, 0755, true);
            }
            if (@rename($srcDir, $dstDir)) {
                $ops[] = ['type' => 'renamedir', 'from' => $srcDir, 'to' => $dstDir];
            } else {
                $warnings[] = 'Ordner konnte nicht verschoben werden: ' . $srcDir;
            }
            return ['skipped' => false, 'ops' => $ops];
        }

        // Beide Ordner existieren → Datei für Datei verschieben, nie überschreiben.
        $suffix = $this->sanitizeSuffix($srcLeaf);
        foreach (scandir($srcDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $srcDir . '/' . $entry;
            if (!is_file($from)) {
                continue; // GATE v1: nur Dateien, keine Unterordner
            }
            $to = $dstDir . '/' . $entry;
            if (file_exists($to)) {
                $to = $this->uniqueCollisionPath($dstDir, $entry, $suffix);
            }
            if (@rename($from, $to)) {
                $ops[] = ['type' => 'move', 'from' => $from, 'to' => $to];
            } else {
                $warnings[] = 'Datei konnte nicht verschoben werden: ' . $from;
            }
        }

        if ($this->isDirEmpty($srcDir) && @rmdir($srcDir)) {
            $ops[] = ['type' => 'rmdir', 'path' => $srcDir];
        }

        return ['skipped' => false, 'ops' => $ops];
    }

    /**
     * @param array<string,mixed> $backup
     */
    private function restoreFolder(array $backup): void
    {
        $ops = $backup['ops'] ?? [];
        if (!is_array($ops)) {
            return;
        }
        // Umgekehrte Reihenfolge: erst rmdir rückgängig (Ordner neu anlegen),
        // dann Moves zurück, zuletzt renamedir.
        foreach (array_reverse($ops) as $op) {
            switch ($op['type'] ?? '') {
                case 'renamedir':
                    @rename($op['to'], $op['from']);
                    break;
                case 'move':
                    $dir = dirname($op['from']);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    @rename($op['to'], $op['from']);
                    break;
                case 'rmdir':
                    if (!is_dir($op['path'])) {
                        @mkdir($op['path'], 0755, true);
                    }
                    break;
            }
        }
    }

    private function uniqueCollisionPath(string $dir, string $filename, string $suffix): string
    {
        $candidate = $dir . '/' . $this->suffixName($filename, $suffix);
        $n = 2;
        while (file_exists($candidate)) {
            $candidate = $dir . '/' . $this->suffixName($filename, $suffix . '_' . $n);
            $n++;
        }
        return $candidate;
    }

    private function suffixName(string $filename, string $suffix): string
    {
        $dot = strrpos($filename, '.');
        if ($dot === false || $dot === 0) {
            return $filename . '__von_' . $suffix;
        }
        return substr($filename, 0, $dot) . '__von_' . $suffix . substr($filename, $dot);
    }

    private function sanitizeSuffix(string $leaf): string
    {
        $s = preg_replace('/[^a-z0-9_-]/', '_', strtolower($leaf)) ?? '';
        $s = trim($s, '_');
        return $s === '' ? 'quelle' : $s;
    }

    private function isDirEmpty(string $dir): bool
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                return false;
            }
        }
        return true;
    }
}
