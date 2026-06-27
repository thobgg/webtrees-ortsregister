<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Ein Wikimedia-Commons-Bild mit Vorschau + Lizenz-Metadaten.
 */
final class WikiImage
{
    public function __construct(
        public readonly string $thumbUrl,        // direkte URL des Bild-Thumbnails
        public readonly string $descriptionUrl,  // Commons-Beschreibungsseite (für Lightbox-„Quelle"-Link)
        public readonly string $title,           // Datei-Titel ohne „File:"-Prefix
        public readonly string $license,         // z.B. „CC BY-SA 4.0"
        public readonly string $author,          // HTML-Snippet aus Commons-extmetadata.Artist
    ) {}
}
