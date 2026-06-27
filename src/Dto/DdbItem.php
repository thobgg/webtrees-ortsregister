<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Ein DDB-Suchresultat (1 Dokument).
 *
 * Quelle: https://api.deutsche-digitale-bibliothek.de/search → results[].docs[]
 *         https://api.deutsche-digitale-bibliothek.de/items/{id} → für Vorschau-Bild
 */
final class DdbItem
{
    public function __construct(
        public readonly string  $id,           // z.B. "ABCDEF12345"
        public readonly string  $label,        // Titel des Eintrags
        public readonly string  $subtitle,     // Untertitel / Beschreibung (oft Archiv-Signatur)
        public readonly string  $media,        // Medien-Typ („text", „image", „mediatype_002" …)
        public readonly ?string $thumbnailUrl, // Direkte URL eines Vorschaubilds, oder null
    ) {}

    public function pageUrl(): string
    {
        return 'https://www.deutsche-digitale-bibliothek.de/item/' . rawurlencode($this->id);
    }
}
