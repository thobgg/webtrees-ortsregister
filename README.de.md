# Ortsregister – webtrees Custom Module

[🇬🇧 English](README.md) · 🇩🇪 **Deutsch**

**Visuelle Landing-Page pro Ort mit Hauptfoto, Medien-Verknüpfung und (geplant) GOV-Integration.**

| | |
|---|---|
| Modul-Name | `ortsregister` |
| Version | 0.1.0 (in Entwicklung) |
| webtrees | 2.2.x |
| PHP | 8.2 – 8.4 |
| Lizenz | GPL-3.0-or-later |

---

## Idee

webtrees ist streng GEDCOM-konform und bietet nur eine sehr nüchterne Orts-Verwaltung.
`Ortsregister` ergänzt die emotionale Familien-Sicht auf Orte:

- **Visuelle Landing-Page** pro Ort: Hauptfoto, Beschreibung, Galerie
- **Personen-Ereignisse** an diesem Ort (Geburten, Hochzeiten, Tode)
- **Medien-Verknüpfung**: Fotos einem Ort zuordnen
- **GOV-Integration** (geplant): historische Verwaltungszugehörigkeit, Kirchspiele, Archivlinks – ggf. via Vesta-Modul

Das Modul tritt **nicht in Konkurrenz** zum Standard-Orte-Modul von webtrees oder
zur Vesta-Modul-Familie, sondern fokussiert sich auf die UX-Schicht.

## Funktionsumfang (aktueller Stand)

- Listenansicht aller Orte mit Server-seitiger DataTables-Paginierung
- Volltextfilter
- Leaflet-Karte mit MarkerCluster
- Ort-Detail-Seite mit Personen-/Familienlisten

## Roadmap

| Stufe | Inhalt |
|---|---|
| 1 | Eigenes Datenmodell: `ortsregister_ort`, `ortsregister_ort_medium` |
| 2 | Foto-Verknüpfung: 📍-Button in Lightbox „Diesem Ort zuordnen" |
| 3 | Visuelle Landing-Page mit Hauptfoto und Galerie |
| 4 | GOV-Integration (eigenständig oder über Vesta-API) |

## Voraussetzungen

- webtrees ≥ 2.2.0
- PHP ≥ 8.2
- MariaDB / MySQL ≥ 10.5

## Installation

```bash
cd modules_v4
git clone https://github.com/thobgg/webtrees-ortsregister.git ortsregister
cd ortsregister
composer install --no-dev
```

In webtrees unter **Steuerleiste → Module → Custom Modules** aktivieren.

## Architektur

```
ortsregister/
├── module.php                          ← webtrees-Einstiegspunkt
├── composer.json
├── src/
│   ├── OrtsregisterModule.php         ← Modul-Hauptklasse
│   ├── Cache/                          ← APCu-Cache
│   ├── Dto/OrtDto.php                 ← Ort-DTO
│   ├── Http/RequestHandlers/           ← Liste, Detail, Karte
│   ├── Repository/OrteRepository.php  ← DB-Zugriff
│   └── Service/I18nService.php        ← Übersetzung-Helper
├── resources/
│   ├── menu-icon.png                  ← Menü-Icon (transparent on-the-fly)
│   ├── lang/de.po, de.mo              ← Übersetzungen
│   └── views/                          ← orte.phtml, orte-karte.phtml, ort-detail.phtml
└── tests/                              ← PHPUnit 11
```

## Routing

| Route-Name | URL | Methode |
|---|---|---|
| `ortsregister.orte` | `/tree/{tree}/orte` | GET |
| `ortsregister.orte.data` | `/tree/{tree}/orte/data` | GET (JSON) |
| `ortsregister.orte.karte` | `/tree/{tree}/orte/karte` | GET |
| `ortsregister.orte.detail` | `/tree/{tree}/orte/{place_id}` | GET |

## Verhältnis zu anderen Modulen

| Modul | Funktion | Empfehlung |
|---|---|---|
| **webtrees Core** | Standard-Orte-Verwaltung (GEDCOM) | bleibt aktiv |
| **Vesta Gov4Webtrees** | GOV-Daten holen + cachen | optional, kann parallel laufen |

## Lizenz

GPL-3.0-or-later, siehe [LICENSE](LICENSE).

## Autor

Thomas Bugge · thomas@bgg-mail.de
