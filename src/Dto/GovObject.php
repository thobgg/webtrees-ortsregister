<?php

declare(strict_types=1);

namespace Ortsregister\Dto;

/**
 * Geparste Repräsentation eines GOV-API-Objects.
 *
 * Quelle: https://gov.genealogy.net/api/getObject?itemId=<gov_id>
 * Response-JSON-Felder: position (lat/lon), name, type, part-of, located-in, represents
 *
 * Pro Feld: GOV liefert oft Listen mit Zeit-Verläufen (begin/end). Wir
 * speichern hier den primären Wert (oft erstes/aktuellstes Vorkommen).
 * Vollständige Roh-Antwort bleibt zugänglich für Detail-Anzeigen.
 */
final class GovObject
{
    /**
     * @param string                                                                $govId              z.B. "object_152487"
     * @param string                                                                $primaryName        Primärer Name (oft deutsch, ggf. mehrere Sprachen)
     * @param array<string, string>                                                 $namesByLang        Lang-Code → Name (z.B. ['de' => 'Tuttlingen', 'en' => 'Tuttlingen'])
     * @param list<string>                                                          $typeIds            GOV-Type-IDs (z.B. ['j.1:9'] = Stadt)
     * @param float|null                                                            $latitude
     * @param float|null                                                            $longitude
     * @param list<string>                                                          $partOfIds          Hierarchie: GOV-IDs der Eltern (Kreis, Land, etc.)
     * @param list<string>                                                          $locatedInIds       Räumliche Zugehörigkeit
     * @param list<string>                                                          $externalUrls       URLs zu Wikipedia, OSM, Wikidata, etc.
     * @param array<string, mixed>                                                  $rawJson            Original-Antwort für Roh-Anzeige
     * @param array<string, array{begin: string|null, end: string|null}>            $partOfMeta         Pro partOf-Ref-ID die Zeitspanne (begin/end Strings wie GOV sie liefert, oft Jahreszahlen)
     */
    public function __construct(
        public readonly string $govId,
        public readonly string $primaryName,
        public readonly array  $namesByLang,
        public readonly array  $typeIds,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly array  $partOfIds,
        public readonly array  $locatedInIds,
        public readonly array  $externalUrls,
        public readonly array  $rawJson,
        public readonly array  $partOfMeta = [],
    ) {}

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** OSM-Relation-IDs aus den externen URLs (für Polygon-Lookup, Inkrement 3C). */
    public function osmRelationIds(): array
    {
        $out = [];
        foreach ($this->externalUrls as $url) {
            if (preg_match('#openstreetmap\.org/relation/(\d+)#', $url, $m) === 1) {
                $out[] = (int) $m[1];
            }
        }
        return $out;
    }
}
