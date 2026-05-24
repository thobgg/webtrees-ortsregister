# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung: [SemVer](https://semver.org/lang/de/).

## [Unreleased]

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
