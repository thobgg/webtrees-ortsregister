# Ortsregister – webtrees Custom Module

[🇬🇧 English](README.md) · 🇩🇪 **Deutsch**

**🏡 Dein mitwachsendes Orts-Archiv für webtrees.** Jeder Ort deines Stammbaums wird
zur Archiv-Seite, die mit jeder Recherche reicher wird: Digitalisate, Kirchenbücher,
Quellen, Karten und Forschungstagebuch an einem Ort.

🌍 GOV-verankert · 🧹 Schreibvarianten sicher zusammengeführt (Vorschau · Backup · Undo) · 🔓 offene Formate, kein Lock-in.

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

## Was es kann

**Jeder Ort in deinem Stammbaum wird zur Archiv-Seite, die mit jeder Recherche reicher wird.** Was du über ein Dorf, eine Pfarrei, eine Stadt zusammenträgst, sammelt sich an einem Ort — dauerhaft, geordnet, auffindbar.

🗂️ **Dein Archiv bekommt ein Zuhause.** Digitalisate, Kirchenbücher, Quellen, Karten, Notizen und dein Forschungstagebuch — direkt am Ort, wo du sie suchst. Schluss mit verstreuten Funden in Ordnern, Mails und Notizzetteln.

🧹 **Damit dein Archiv sauber adressiert bleibt.** „Brackenheim", „Brakenheim", „Brackenheim a.N." — derselbe Ort, drei Schreibweisen? Ortsregister erkennt zusammengehörige Orte und führt sie sicher zusammen: mit Vorschau, vollständigem Backup und Rückgängig-Funktion. Deine kuratierten Daten reisen mit — keine Notiz, kein Digitalisat geht verloren.

🌍 **Wasserdicht verankert.** Die GOV-Anbindung verknüpft deine Orte mit dem offiziellen historischen Ortsverzeichnis: stabile Kennung, historische Namensvarianten, Hierarchie und Koordinaten. Dazu Treffer aus Archion, Deutscher Digitaler Bibliothek und Wikimedia, direkt auf der Ortsseite.

🔎 **Zeigt dir, wo Arbeit liegt.** Welche Orte haben noch keine Koordinaten? Keine GOV-Kennung? Welche sehen nach Dubletten aus? Ortsregister macht aus deiner Ortsliste eine Arbeits-Warteschlange.

🔓 **Deine Daten bleiben deine Daten.** Alles liegt im offenen Standard seiner Art — im Stammbaum oder als lesbare Datei im Orts-Ordner. Die Datenbank ist nur ein Index, jederzeit neu aufbaubar. Kein proprietäres Format, kein Vendor-Lock-in.

Das Modul tritt **nicht in Konkurrenz** zum Standard-Orte-Modul von webtrees oder
zur Vesta-Familie — es ist die UX- und Archiv-Schicht darüber.

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
- **Koordinaten-Import** aus PLAC-Subtags ins webtrees-`place_location`

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

## Bekannte Einschränkungen (Alpha)

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

## Roadmap

Aktueller Funktionsstand: [CHANGELOG](CHANGELOG.md). Die Alpha sammelt Tester-Feedback;
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
