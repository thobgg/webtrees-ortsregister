<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Notes-Datei für einen Ort (Markdown).
 *
 * `mtime` ist der Filesystem-Modifizierungs-Zeitpunkt zum Lade-Zeitpunkt —
 * wird als Optimistic-Lock im Save-Handler verglichen, um Überschreiben
 * paralleler Edits zu vermeiden.
 */
final class PlaceNotes
{
    public function __construct(
        public readonly string $markdown,
        public readonly int    $mtime,   // 0 wenn Datei nicht existiert (neu)
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->markdown) === '';
    }

    public static function empty(): self
    {
        return new self('', 0);
    }
}
