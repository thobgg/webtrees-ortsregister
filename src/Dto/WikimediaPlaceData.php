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
     * @param list<WikiImage>       $galerie
     * @param array<string,string>  $wikipediaSitelinks  Sprach-Code → Wikipedia-Artikel-URL (aus Wikidata; sprach-unabhängig, cache-sicher)
     */
    public function __construct(
        public readonly ?string    $qid,
        public readonly ?WikiImage $hauptbild,
        public readonly array      $galerie,
        public readonly array      $wikipediaSitelinks = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->hauptbild === null && $this->galerie === [];
    }

    /**
     * Wikipedia-Artikel in der Nutzersprache (Fallback: Primär-Subtag → de → en → irgendeiner).
     * Löst Hermanns Kritik: exakter Artikel statt Namens-Rate-Link, in der Sprache des Nutzers.
     */
    public function wikipediaUrl(string $lang): ?string
    {
        // Cache-Altbestand: ein vor diesem Feld gecachtes (serialisiertes) Objekt hat
        // die Property nicht initialisiert — Zugriff wäre ein Fatal (500 auf der
        // Ortsseite). isset() ist auf uninitialisierten typed Properties false → null.
        if (!isset($this->wikipediaSitelinks)) {
            return null;
        }
        $primary = strtolower(explode('-', $lang)[0]);
        foreach ([strtolower($lang), $primary, 'de', 'en'] as $l) {
            if (($this->wikipediaSitelinks[$l] ?? '') !== '') {
                return $this->wikipediaSitelinks[$l];
            }
        }
        if ($this->wikipediaSitelinks === []) {
            return null;
        }
        return (string) $this->wikipediaSitelinks[array_key_first($this->wikipediaSitelinks)];
    }

    public static function empty(): self
    {
        return new self(null, null, [], []);
    }
}
