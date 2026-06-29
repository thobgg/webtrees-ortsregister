# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung: [SemVer](https://semver.org/lang/de/).

## [0.2.0-alpha] – 2026-06-29

### Phase 4 — Orts-Hygiene-Cockpit (Merge / Rename / Undo)
- **Sidecar-Vereinigung beim Merge**: Notizen, Kirchenbücher, GOV-Verknüpfung,
  Aufgaben und Digitalisate des Quell-Orts wandern ins Ziel statt zu verwaisen
  (`PlaceSidecarMerger`). Behebt den bisherigen `mergePlaceMeta`-Crash
  (PK-Duplicate). Backup-Format v2 + Undo-Restore der Sidecar-Schicht.
- **Rename-ohne-Merge**: einen Ort umbenennen (propagiert auf alle Ereignisse);
  existiert der neue Name bereits → Hinweis, stattdessen zu mergen.
- **Reversibles Undo mit Stale-Schutz**: bricht ab, wenn ein betroffener Datensatz
  seit der Operation geändert wurde (überschreibt keine späteren Edits).
- **GOV-Statusspalte** in der Ortsliste (verknüpft / nicht).
- **Wächter & Warnungen**: degenerierter-Merge-Hinweis („X" vs „X."), Warnung bei
  großen Merges (Single-Transaction), GOV-/Koordinaten-Konflikt-Hinweise im Modal.
- **Robuste AJAX-Fehlerbehandlung**: Geschäftsfehler als HTTP 200 + `success:false`
  (kein „JSON.parse"-Crash mehr), UTF-8-tolerantes `json_encode` in allen Handlern.
- **UI-Fixes**: Font-Awesome-Subset-konforme Icons, voller Hierarchie-Pfad in der
  Liste (macht namensgleiche Orte unterscheidbar), dismissbarer Endorte-Hinweis.
- **Tests**: `GedcomPlaceMergeEdgeCasesTest` (Compound-PLAC, Suffix-Over-Capture,
  Trailing-Dot, Substring-Falle). PHP-8.5-Lauf: alle 75 Tests grün, Lint sauber.
- **Englische Lokalisierung**: vollständige `en.po` (167 Strings, Deutsch→Englisch),
  `de.po` auf den aktuellen String-Satz resynct. `msgfmt --check-format` grün
  (Format-Platzhalter konsistent). `.mo` werden zur Laufzeit kompiliert (git-ignored).

### Hinzugefügt
- **Merge-Operation für Orte**: `PlaceOperationService` mit analyzeMerge /
  executeMerge / undoMerge. Opake Subtag-Übernahme, Konflikt-Resolve-Modal,
  Backup-JSON pro Operation, Suffix-Match über mittlere Hierarchie-Ebenen.
- **Hierarchie-Filter** in der Liste: „Alle Ebenen" vs. „Nur Endorte" (Blätter
  ohne Place-Kinder). Persistiert pro User. Default „Alle Ebenen".
- **Koordinaten-Import**: `MAP/LATI/LONG`-Subtags aus PLAC-Strukturen werden
  in die webtrees-Standardtabelle `place_location` übertragen. Adressiert
  Ahnenblatt/Gramps/FTM/MyHeritage-Exporte, deren Koordinaten webtrees
  sonst ignoriert. Idempotent — überschreibt keine vorhandenen Koordinaten.
- **Merge-Spalte einklappbar** via „Merge-Modus"-Button. Standard-Ansicht
  kompakt, Auswahl-Radios nur bei expliziter Aktivierung.
- DB-Tabellen `ortsregister_place_meta` (leer, Vorbereitung Phase 4) und
  `ortsregister_merge_log` (Operations-Historie).
- PHPUnit-11-Test-Suite mit Tests für `GedcomPlaceManipulator` und
  `GedcomCoordinateExtractor`.
- **GOV-Integration (Phase 3A)**: manuelles Linking von Places mit GOV-IDs
  (gov.genealogy.net). `GovApiClient` mit Cache (7d TTL), `GovObject`-DTO,
  `GovLinkingService`. Pro Ort ein Modal mit GOV-ID-Eingabe + Verifikation.
  Neue Spalte `ortsregister_place_meta.gov_id` (Migration SCHEMA_VERSION 2).
- **GOV-Hierarchie auf Detailseite (Phase 3E)**: bei verknüpftem Ort wird
  die `part-of`-Kette aus GOV rekursiv aufgelöst (max. 10 Stufen, Cycle-Safe,
  via gecachtem `GovApiClient`) und als Breadcrumb angezeigt — verlinkt direkt
  auf gov.genealogy.net. Bei nicht-verknüpften Orten zeigt der Block die
  PLAC-Komma-Hierarchie + „Jetzt mit GOV verknüpfen"-Button (öffnet das
  bestehende GOV-Modal direkt auf der Detailseite).
- **Detailseite kompakter + aussagekräftiger (Phase 3F)**:
  - Statistik-Karten splitten Ereignisse nach Typ (Geburten / Heiraten /
    Todesfälle / Weitere) statt aggregiert. `PlaceEventCounter` parsiert
    die GEDCOM-Blobs der verknüpften INDI/FAM-Records.
  - Lange Listen werden gekappt: Personen 10, Medien 5, Bilder-Grid 12 —
    Rest in Bootstrap-Collapse mit „Alle N anzeigen"-Button.
- **Admin-Konfiguration (Phase 3L)**: neuer Bereich
  `Verwaltung → Module → Ortsregister`. Konfigurierbar:
  - Wikimedia-Lookup an/aus
  - Max. Distanz Wikidata ↔ GOV-Koordinaten (default 30 km)
  - Cache-TTLs für Wikimedia + GOV separat
  - Sichtbare Listen-Längen (Personen / Medien / Bilder)
  Implementiert `ModuleConfigInterface`. Defaults bleiben bei
  ungesetzten Werten erhalten — Update bricht nichts.
- **Wikimedia-Integration (Phase 3J-1)**: zu jedem Ort wird Wikidata
  nach dem Ortsnamen durchsucht (max. 5 Kandidaten), gegen die GOV-
  Koordinaten geo-validiert (max. 30 km Abstand — verwirft Namensgleiche
  in fremden Regionen). Bei Treffer wird Wikidata-P18 als Hauptbild
  geladen (Fallback wenn kein webtrees-Headerbild gepflegt ist) und
  zusätzlich eine Commons-Galerie aus passenden File-Treffern (max. 6,
  ohne SVG/PNG). Lizenz-Hinweise pro Bild. Neue Services:
  `WikimediaPlaceClient`, DTOs `WikiImage` und `WikimediaPlaceData`.
  Cache 7 Tage, vier API-Calls pro Ort beim ersten Aufruf.
- **GOV-Hierarchie mit Zeitspannen (Phase 3H-1)**: pro Hierarchie-Stufe
  wird die Zeitspanne der part-of-Zugehörigkeit angezeigt
  („Württemberg (1806–1813)"). Lead-Hinweis oben im Block zeigt die
  Epoche der unmittelbaren Elternstufe, damit klar wird, dass die
  Hierarchie historisch ist und für andere Epochen abweichen kann.
  `GovHierarchyResolver::resolveWithEdges()` liefert pro Stufe begin/end
  der Edge zur vorherigen Stufe; `GovObject.partOfMeta` cached die
  Zeitspannen je Ref-ID aus der GOV-API.
- **Detailseite visuell aufgewertet (Phase 3G)** — Patterns aus Nachbar-Modul
  „Sammlungen" adaptiert:
  - Medien-Liste mit farbigen Format-Badges (PDF rot, Video/Audio,
    Word/Excel etc.) statt nur Text-Link.
  - Bilder-Galerie: dichter Grid (3/4/5/6 Cols responsive), Tiles
    streng quadratisch (`aspect-ratio: 1/1`) mit Hover-Overlay
    (Caption + Lift-Schatten).
  - **Lightbox** für Galerie-Bilder: Click → großes Modal mit
    Prev/Next-Navigation, Pfeiltasten-Support, Position-Indikator,
    Link „In webtrees öffnen". Vanilla JS, ~80 Zeilen inline.

### Geändert
- GOV-ID-Format: kompakte IDs wie `HABCHTJN49MC` werden zusätzlich zum
  Legacy-Format `object_NNN` akzeptiert (Regex `[A-Za-z0-9_]{3,40}`).
- GOV-Externsuche-URL: `/search/name?name=` statt veraltetem
  `/search/simple?placename=` (404 seit Juni 2026).
- Help-Text zu GOV-ID-Format: keine falschen Typ-Behauptungen mehr
  am ID-Prefix (GOV-Typ steht im `type`-Feld der API-Antwort).

## [0.1.0] – 2026-05-22

### Erstes eigenständiges Release (Pre-Release)

Enthält die heutige Orte-Funktionalität als Basis für den geplanten weiteren Ausbau.

### Hinzugefügt
- Listenansicht aller Orte mit Server-seitiger DataTables-Paginierung
- Volltextfilter
- Leaflet-Karte mit MarkerCluster
- Ort-Detail-Seite mit Personen-/Familienlisten
- Menü-Icon mit On-the-fly-Transparenz (Imagick)

### Architektur
- Eigener Namespace `Ortsregister\`
- Eigene Composer-Konfiguration mit `autoloader-suffix` (verhindert Autoloader-Kollisionen)
- Test-Suite (PHPUnit 11) mit Unit- und Integration-Tests (SQLite In-Memory)

### Roadmap (kommende Versionen)
- v0.2.0: Eigenes Datenmodell `ortsregister_ort` + `ortsregister_ort_medium`
- v0.3.0: Foto-Verknüpfung (Ort ↔ Medium)
- v0.4.0: Visuelle Landing-Page mit Hauptfoto + Galerie
- v0.5.0: GOV-Integration
