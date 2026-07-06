# Ortsregister – webtrees Custom Module

[🇬🇧 English](README.md) · 🇩🇪 **Deutsch**

**Dein Familienarchiv in webtrees, geordnet nach Orten.** Jeder Ort im Stammbaum wird zu einer Archiv-Seite mit Fotos, Kirchenbüchern, Quellen, Karte und Notizen. Dazu Werkzeug, um die Orte selbst in Ordnung zu halten (Zusammenführen und Umbenennen mit Vorschau, Backup und Undo).

| | |
|---|---|
| Modul-Name | `ortsregister` |
| Version | 1.0.10 |
| webtrees | 2.2.x |
| PHP | 8.2 – 8.4 |
| Lizenz | GPL-3.0-or-later |

---

## ⚠️ Vorab — dieses Modul schreibt in deinen Stammbaum

**Dieses Modul schreibt in deinen Stammbaum.** *Merge* und *Umbenennen* schreiben
die `PLAC` betroffener Datensätze über die native webtrees-Edit-API um. Da es
Datensätze verändert, triff die üblichen Vorsichtsmaßnahmen:

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
- **GOV-Statusspalte** + GOV-Verknüpfung pro Ort, GOV-Hierarchie auf der Detailseite
- Leaflet-Karte mit MarkerCluster
- **Detailseite** pro Ort: Ereignis-Statistik (Geburten/Heiraten/Tode), Medien-Galerie
  mit Lightbox, Notizen/Aufgaben/Kirchenbuch-Logbuch (Markdown)
- **Orts-Hygiene**: Merge & Umbenennen mit Vorschau, JSON-Backup und Undo; kuratierte
  Daten (Notizen/KB/GOV/Digitalisate) wandern mit
- **Externe Treffer** auf der Detailseite: Wikimedia/Commons, Deutsche Digitale
  Bibliothek, Archion-Auto-Pfarrei-Lookup

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
- **Kuratierte Archiv-Daten liegen nicht im GEDCOM.** Notizen, Kirchenbuch-Logbücher,
  Aufgaben und Digitalisate liegen als Dateien im Orts-Ordner (`media/orte/…`) plus einem
  DB-Index — **nicht** im Stammbaum. Ein GEDCOM-Export trägt sie also nicht mit; sichere
  den Orts-Ordner separat. (Bewusste Entscheidung: offene, lesbare Dateien statt
  proprietärer GEDCOM-Erweiterungen.)

## Roadmap

Aktueller Funktionsstand: [CHANGELOG](CHANGELOG.md). Feedback ist willkommen;
als Nächstes: record-genauer Split (einzelne Ereignisse von einem Sammel-Ort lösen) und
Ausbau der Dubletten-Erkennung.

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
