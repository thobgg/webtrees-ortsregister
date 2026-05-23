<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Ergebnis von PlaceOperationService::executeMerge().
 */
final class MergeResult
{
    public function __construct(
        public readonly int    $sourcePlaceId,
        public readonly int    $targetPlaceId,

        /** Wieviele Records wurden tatsächlich modifiziert */
        public readonly int    $modifiedRecords,

        /** Absoluter Pfad zur Backup-JSON-Datei (für Undo) */
        public readonly string $backupPath,

        /** ID des Eintrags in `ortsregister_merge_log` */
        public readonly int    $logId,
    ) {}
}
