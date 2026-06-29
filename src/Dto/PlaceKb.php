<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Ein Kirchenbuch-Eintrag pro Ort (Modul-eigene Liste, optional verlinkt mit webtrees-SOUR).
 *
 * Storage: `media/<root>/<ortsname>/_kb_list.json`
 * Logbuch: separates `media/<root>/<ortsname>/_kb_<id>.md` (Markdown)
 *
 * Wenn `sourXref` gesetzt UND die SOUR im Tree existiert → Tree-Titel hat
 * Vorrang über `title` (Konsens 2026-06-28). `title` ist Fallback bei fehlender
 * oder kaputter Verknüpfung.
 */
final class PlaceKb
{
    /** Bekannte Typen — Dropdown im Editor. Frei lassbar wenn etwas anderes. */
    public const TYPES = [
        'taufen'        => 'Taufen',
        'heiraten'      => 'Heiraten',
        'beerdigungen'  => 'Beerdigungen',
        'konfirmation'  => 'Konfirmation',
        'familienbuch'  => 'Familienbuch',
        'mischbuch'     => 'Misch-/Sammelbuch',
        'sonstige'      => 'Sonstige',
    ];

    public function __construct(
        public readonly string  $id,
        public readonly string  $title,
        public readonly string  $type        = 'sonstige',
        public readonly ?int    $yearFrom    = null,
        public readonly ?int    $yearTo      = null,
        public readonly ?string $archionUrl  = null,
        public readonly ?string $sourXref    = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'type'        => $this->type,
            'year_from'   => $this->yearFrom,
            'year_to'     => $this->yearTo,
            'archion_url' => $this->archionUrl,
            'sour_xref'   => $this->sourXref,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $type = (string) ($raw['type'] ?? 'sonstige');
        if (!isset(self::TYPES[$type])) {
            $type = 'sonstige';
        }
        return new self(
            id:         (string) ($raw['id']    ?? ''),
            title:      (string) ($raw['title'] ?? ''),
            type:       $type,
            yearFrom:   isset($raw['year_from']) ? (int) $raw['year_from'] : null,
            yearTo:     isset($raw['year_to'])   ? (int) $raw['year_to']   : null,
            archionUrl: self::strOrNull($raw, 'archion_url'),
            sourXref:   self::strOrNull($raw, 'sour_xref'),
        );
    }

    public function timespanLabel(): string
    {
        if ($this->yearFrom !== null && $this->yearTo !== null) {
            return $this->yearFrom . '–' . $this->yearTo;
        }
        if ($this->yearFrom !== null) {
            return 'ab ' . $this->yearFrom;
        }
        if ($this->yearTo !== null) {
            return 'bis ' . $this->yearTo;
        }
        return '';
    }

    /** @param array<string, mixed> $r */
    private static function strOrNull(array $r, string $k): ?string
    {
        if (!isset($r[$k]) || !is_scalar($r[$k])) {
            return null;
        }
        $s = trim((string) $r[$k]);
        return $s === '' ? null : $s;
    }
}
