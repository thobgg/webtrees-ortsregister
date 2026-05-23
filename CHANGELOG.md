# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung: [SemVer](https://semver.org/lang/de/).

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
