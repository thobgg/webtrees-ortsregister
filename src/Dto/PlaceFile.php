<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Eine Datei im Ortsbilder-Ordner (media/<root>/<ortsname>/...).
 * Existiert OHNE webtrees-OBJE-Record — wird direkt vom Filesystem geliefert.
 */
final class PlaceFile
{
    public function __construct(
        public readonly string $filename,      // basename
        public readonly string $relativePath,  // pfad relativ zu MEDIA_DIRECTORY (für MediaDateiServe ?pfad=…)
        public readonly string $extension,     // lowercase ohne Punkt
        public readonly int    $sizeBytes,
        public readonly int    $mtimeUnix,
    ) {}

    public function isImage(): bool
    {
        return in_array($this->extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }
}
