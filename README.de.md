# Ortsregister – webtrees Custom Module

[🇬🇧 English](README.md) · 🇩🇪 **Deutsch**

**Dein Familienarchiv in webtrees, geordnet nach Orten.** Jeder Ort im Stammbaum wird zu einer Archiv-Seite mit Fotos, Kirchenbüchern, Quellen, Karte und Notizen. Dazu Werkzeug, um die Orte selbst in Ordnung zu halten (Zusammenführen und Umbenennen mit Vorschau, Backup und Undo).

| | |
|---|---|
| Modul-Name | `ortsregister` |
| Version | 1.8.1 |
| webtrees | 2.2.x |
| PHP | 8.2 – 8.4 |
| Lizenz | GPL-3.0-or-later |

---

## ⚠️ Vorab — dieses Modul schreibt in deinen Stammbaum

**Dieses Modul schreibt in deinen Stammbaum.** *Merge* und *Umbenennen* schreiben
die `PLAC` betroffener Datensätze über die native webtrees-Edit-API um. Die optionale
Aktion *_LOC-Identität* legt zusätzlich pro Ort *einen* `_LOC`-Record additiv an bzw.
ergänzt ihn (Orts-Identität im GEDCOM-L-Standard) — opt-in, mit Vorschau, und sie
überschreibt nie bestehende Werte. Da es Datensätze verändert, triff die üblichen
Vorsichtsmaßnahmen:

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

## Warum es das gibt

Ich wollte mein Familienarchiv in webtrees, nicht daneben. Vorher lag die Forschung in Ahnenblatt oder Gramps, die Fotos, Urkunden und Digitalisate irgendwo sonst. webtrees verwaltet den Stammbaum sauber und bringt ein durchdachtes Rechtesystem mit. Eine Medienverwaltung hat es auch, aber die zeigt nur, was als Medienobjekt im GEDCOM steckt.

Ortsregister ordnet das Archiv nach Orten. Die Dateien liegen in Ordnern und werden angezeigt, ohne dass jede erst in den Stammbaum importiert sein muss. Jeder Ort wird zu einer Seite, auf der zusammenkommt, was du über ihn hast: Fotos, Kirchenbücher, Quellen, Karte, Notizen, ein Recherche-Tagebuch. Dazu Werkzeug, um die Orte in Ordnung zu halten, also Schreibvarianten zusammenzuführen oder umzubenennen, mit Vorschau und Backup.

Alles bleibt in offenen Formaten, als Datei im Ordner oder im Stammbaum. Die Datenbank ist nur ein Index. Für mich ist das eine Plattform, die mir gehört und in die ich reinwachse, wenn ich wieder mehr Zeit für die Forschung habe. Das Schwestermodul Sammlungen macht dasselbe, nur nach Themen statt nach Orten.

## Screenshots

Eine Orts-Archivseite — GOV-verankerte Hierarchie, Ereigniszähler, externe Quellen, Notizen und Galerie auf einen Blick:

![Ortsregister Orts-Archivseite](docs/images/ortsregister-landing.png)

Orte sicher zusammenführen — Quelle → Ziel mit Anzahl betroffener Datensätze, Warnungen und automatischem JSON-Backup, bevor etwas geschrieben wird:

![Ortsregister Merge-Dialog](docs/images/ortsregister-merge.png)

Ein Recherche-Log pro Ort in Markdown, mit Task-Lists und Ein-Klick-Verlinkung zu Personen im Stammbaum:

![Ortsregister Recherche-Editor](docs/images/ortsregister-research-log.png)

Leaflet-/OpenStreetMap-Ansicht, der Ort aus seinen Koordinaten verortet:

![Ortsregister Kartenansicht](docs/images/ortsregister-place-map.png)

## Funktionsumfang (aktueller Stand)

- Listenansicht aller Orte (Server-seitige DataTables-Paginierung, Volltextfilter)
- **Hierarchie-Filter**: „Alle Ebenen" vs. „Nur Endorte" (siehe unten)
- **GOV-Statusspalte** + GOV-Verknüpfung pro Ort, GOV-Hierarchie auf der Detailseite,
  und die externen GOV-Kennungen (GND, GeoNames, LEO-BW, Wikidata) als fertige Links
- Leaflet-Karte mit MarkerCluster
- **Detailseite** pro Ort: Ereignis-Statistik (Geburten/Heiraten/Tode), Medien-Galerie
  mit Lightbox, Notizen/Aufgaben/Kirchenbuch-Logbuch (Markdown)
- **Orts-Hygiene**: Merge & Umbenennen mit Vorschau, JSON-Backup und Undo; kuratierte
  Daten (Notizen/KB/GOV/Digitalisate) wandern mit
- **Externe Treffer** auf der Detailseite: Wikimedia/Commons, Deutsche Digitale
  Bibliothek, Archion-Auto-Pfarrei-Lookup, dazu ein Wikipedia-Link, der (über Wikidata) den
  exakten Artikel in der Sprache des Nutzers trifft statt einer deutschen Namens-Suche
- **GEDCOM-L `_LOC`-Identitätsschicht**: erkennt vorhandene `_LOC`-Records und zeigt sie
  pro Ort; optionales additives Schreiben von GOV-Kennung und Koordinaten in einen
  Standard-`_LOC`-Record (Vorschau zuerst, nur Lücken füllen, nie überschreiben); und
  optionales Verknüpfen der Ereignisse eines Orts mit diesem `_LOC`-Record (der `_LOC`-Zeiger
  unter der Ereignis-`PLAC`), damit der Baum standard-portabel wird — additiv, der
  `PLAC`-Text bleibt, mit Vorschau und Undo.
  Die **Orts-Beschreibung** wird als `_LOC` NOTE gespeichert, **Orts-Aufgaben** als
  GEDCOM-L-Forschungsaufgaben (`_TODO` mit Datum, Bearbeiter und Status) am `_LOC`,
  und das GOV-Verknüpfen **verankert die GOV-Kennung im `_LOC`** — diese kuratierten
  Daten reisen so im GEDCOM-Export mit, statt nur in der Modul-Datenbank zu liegen.
  Die Ortsseite **spiegelt zusätzlich den Inhalt des `_LOC`-Records lesend** — vorhandene
  Fotos (`OBJE`), Notizen, Ereignisse (`EVEN`) und demografische Angaben wie Einwohner-
  zahlen (`_DMGD`) des Records werden angezeigt (die Beschreibung behält ihre eigene Karte
  und wird nicht doppelt gezeigt)
- **Zeit-bewusste Orts-Identität**: Koordinaten werden über den vollen Hierarchie-Pfad
  aufgelöst — gleichnamige Orte teilen sie nicht; die GOV-Hierarchie zeigt die historische
  Kette mit Jahren und die heutige Zugehörigkeit; Orte, die über Verwaltungsreformen
  derselbe sind (gleiche GOV-Kennung), werden querverwiesen — die historischen
  Schreibweisen bleiben erhalten, werden nie umgeschrieben

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

## Bekannte Einschränkungen

- **Namensgleiche Orte teilen einen Daten-Ordner.** Zwei „Neustadt" auf verschiedenen
  Hierarchie-Ebenen nutzen denselben `media/orte/Neustadt/`-Ordner. Merge/Umbenennen
  warnt dann und überspringt die Ordner-Operation.
- **Dateisystem-Operationen sind nicht transaktional.** Bricht eine Operation nach der
  GEDCOM-Änderung, aber während der Ordner-Verschiebung ab, kann die Sidecar-Ebene
  inkonsistent bleiben (DB/GEDCOM werden sauber zurückgerollt).
- **Auto-Accept nötig.** Merge/Umbenennen erfordern „Änderungen automatisch übernehmen"
  und umgehen den Moderations-/Pending-Changes-Workflow.
- **Sehr große Merges** (z. B. ganze Länder) laufen in einer Transaktion ohne Batching —
  bei tausenden Datensätzen droht Timeout/Speicher (es wird gewarnt).
- **Undo** ist sicher direkt nach einer Operation; wurde ein Datensatz seither geändert,
  bricht es ab (überschreibt nichts) — kein vollständiges Versions-Undo.
- **Medien und einzelne Logbücher liegen als Dateien, nicht im GEDCOM.** Beschreibung
  und Aufgaben eines Orts leben inzwischen im `_LOC`-Record und reisen im GEDCOM-Export
  mit. Digitalisate, das Recherche-Logbuch und die Kirchenbuch-Liste liegen dagegen als
  offene Dateien im Orts-Ordner (`media/orte/…`) plus einem DB-Index — sichere den
  Orts-Ordner separat. (Bewusste Entscheidung: ein Scan gehört als Datei ins Archiv,
  nicht als Struktur in den Stammbaum.)

## Roadmap

Aktueller Funktionsstand: [CHANGELOG](CHANGELOG.md). Feedback ist willkommen;
als Nächstes für die `_LOC`-Identitätsschicht: eine zusammengeführte Ortsansicht, die die
Zeit-Varianten eines realen Orts (gleiche GOV-Kennung) auf einer Overlay-Seite bündelt,
dazu record-genauer Split (einzelne Ereignisse von einem Sammel-Ort lösen) und Ausbau der
Dubletten-Erkennung.

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
│   ├── lang/de.po, en.po, nl.po       ← Übersetzungen (de + en + nl)
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

## Lokalisierung

Die Oberfläche liegt in **Deutsch, Englisch und Niederländisch** vor
(`resources/lang/de.po`, `en.po`, `nl.po`, je 167 Strings). webtrees wählt anhand
der Benutzersprache; `.mo`-Dateien werden zur Laufzeit kompiliert (git-ignored).

## Lizenz

GPL-3.0-or-later, siehe [LICENSE](LICENSE).

## Autor

Thomas Bugge · thomas@bgg-mail.de
