<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Eine Pfarrei aus dem archionkarte-20-Datensatz.
 * Quelle: github.com/brger93/archionkarte-20 (MIT, brger93)
 */
final class ArchionParish
{
    public function __construct(
        public readonly string $name,
        public readonly string $archive,
        public readonly string $district,
        public readonly string $path,       // rel. Pfad auf archion.de, z.B. "/de/alle-archive/..."
        public readonly ?float $latitude,
        public readonly ?float $longitude,
    ) {}

    public function fullUrl(string $lang = 'de'): string
    {
        // Path beginnt mit "/de/..." — wir tauschen ggf. die lang aus
        $path = preg_replace('#^/[a-z]{2}/#', '/' . $lang . '/', $this->path) ?? $this->path;
        return 'https://www.archion.de' . $path;
    }
}
