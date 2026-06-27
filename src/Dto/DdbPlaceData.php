<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * DDB-Daten für einen Ort: Gesamt-Trefferzahl + Top-Items mit Vorschau.
 */
final class DdbPlaceData
{
    /**
     * @param list<DdbItem> $items
     */
    public function __construct(
        public readonly int   $total,
        public readonly array $items,
    ) {}

    public function isEmpty(): bool
    {
        return $this->total === 0 && $this->items === [];
    }

    public static function empty(): self
    {
        return new self(0, []);
    }
}
