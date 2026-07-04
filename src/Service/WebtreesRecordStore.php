<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use RuntimeException;

/**
 * Produktions-{@see RecordStore}: liest/schreibt echte GEDCOM-Datensätze eines
 * Baums über die webtrees-Factories bzw. GedcomRecord::updateRecord().
 *
 * Schreibt mit update_chan=false (kein CHAN-Bump) – identisch zum bisherigen
 * Merge/Undo-Verhalten von PlaceOperationService.
 */
final class WebtreesRecordStore implements RecordStore
{
    public function __construct(private readonly Tree $tree) {}

    public function read(string $xref, string $type): ?string
    {
        return $this->resolve($xref, $type)?->gedcom();
    }

    public function write(string $xref, string $type, string $gedcom): void
    {
        $record = $this->resolve($xref, $type);
        if ($record === null) {
            throw new RuntimeException('Datensatz zum Schreiben nicht gefunden: ' . $xref);
        }
        $record->updateRecord($gedcom, false);
    }

    private function resolve(string $xref, string $type): ?GedcomRecord
    {
        return match ($type) {
            'INDI' => Registry::individualFactory()->make($xref, $this->tree),
            'FAM'  => Registry::familyFactory()->make($xref, $this->tree),
            'SOUR' => Registry::sourceFactory()->make($xref, $this->tree),
            'OBJE' => Registry::mediaFactory()->make($xref, $this->tree),
            default => Registry::gedcomRecordFactory()->make($xref, $this->tree),
        };
    }
}
