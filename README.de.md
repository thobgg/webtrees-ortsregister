# Ortsregister – webtrees Custom Module

[🇬🇧 English](README.md) · 🇩🇪 **Deutsch**

**Visuelle Landing-Page pro Ort mit Hauptfoto, Medien-Verknüpfung und (geplant) GOV-Integration.**

| | |
|---|---|
| Modul-Name | `ortsregister` |
| Version | 0.2.0-alpha (Alpha — für Tester) |
| webtrees | 2.2.x |
| PHP | 8.2 – 8.4 |
| Lizenz | GPL-3.0-or-later |

---

## ⚠️ Alpha — für Tester

**Dieses Modul schreibt in deinen Stammbaum.** *Merge* und *Umbenennen* schreiben
die `PLAC` betroffener Datensätze über die native webtrees-Edit-API um. Behandle es als **Alpha**:

- **Vorher vollständigen GEDCOM-Export sichern** (Verwaltung → Stammbäume → Export)
  — nicht nur das modul-eigene JSON-Backup pro Operation.
- **An einer Kopie** deines Baums testen, nicht am Produktivbaum.
- Mit Orten **mit wenigen Ereignissen** beginnen (große Merges laufen in einer
  Transaktion und werden gewarnt).
- Nach jeder Operation betroffene Personen **stichprobenartig prüfen**.
- **Rückgängig** ist direkt nach einer Operation sicher; es **bricht ab**, wenn ein
  betroffener Datensatz zwischenzeitlich geändert wurde — es überschreibt nie spätere Edits.
- Erfordert die Benutzereinstellung **„Änderungen automatisch übernehmen"**
  (Operationen umgehen den Moderations-Workflow).
- Problem gefunden? Bitte ein **GitHub-Issue** öffnen.

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
- **Hierarchie-Filter** (siehe unten): „Alle Ebenen" vs. „Nur Endorte"
- Volltextfilter
- Leaflet-Karte mit MarkerCluster
- Ort-Detail-Seite mit Personen-/Familienlisten
- **Merge-Operation** mit Backup als JSON, opake Subtag-Übernahme, Suffix-Match
  über mittlere Hierarchie-Ebenen

### Hierarchie-Filter

webtrees zerlegt PLAC-Strings an Kommas und legt **pro Ebene** einen eigenen
Place-Record an. Aus `Weiler, Amt Kirchheim, Hzm. Württemberg` entstehen drei
Records (Weiler, Amt Kirchheim, Hzm. Württemberg). Verwaltungs-Zwischenebenen
wie „Amt Kirchheim" sind semantisch keine Orte, tauchen aber in der
Standardliste auf.

**„Nur Endorte"-Modus** blendet alle Hierarchie-Ebenen aus, die Place-Kinder
haben. Es bleiben nur die Blätter der Hierarchie übrig — typischerweise die
„echten" Ortschaften. Pro Nutzer persistiert (User-Preference).

Default: „Alle Ebenen" (kein Datenverlust-Eindruck, konservativ). Toggle
oberhalb der Liste.

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
