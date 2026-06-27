<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Wikimedia-Daten für einen Ort (Wikidata-QID + P18-Hauptbild + Commons-Galerie).
 *
 * `qid` ist null wenn kein passender Wikidata-Eintrag gefunden oder geo-validiert
 * werden konnte. In dem Fall sollten hauptbild/galerie ebenfalls leer sein.
 */
final class WikimediaPlaceData
{
    /**
     * @param list<WikiImage> $galerie
     */
    public function __construct(
        public readonly ?string    $qid,
        public readonly ?WikiImage $hauptbild,
        public readonly array      $galerie,
    ) {}

    public function isEmpty(): bool
    {
        return $this->hauptbild === null && $this->galerie === [];
    }

    public static function empty(): self
    {
        return new self(null, null, []);
    }
}
