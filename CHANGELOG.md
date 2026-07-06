# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung: [SemVer](https://semver.org/lang/de/).

## [Unreleased]

## [1.0.10] – 2026-07-06

### Intern
- **Ort→Ordner-Auflösung konsolidiert.** Die Sidecar-Services `PlaceNotesService`,
  `PlaceTasksService` und `PlaceKbListService` trugen je eine eigene, byte-identische
  Kopie der Ordner-Auflösung (`media/<root>/<ort>/` inkl. Path-Traversal-Schutz). Alle
  drei delegieren jetzt an die eine kanonische `PlaceFolderLocator`-Naht; die doppelten
  Pfad-/Prüf-Blöcke und der ungenutzte `Webtrees`-Import sind weg. Kein Verhalten für
  Nutzer geändert (Tests unverändert grün) — es ist die Vorbedingung für die kommende
  `_LOC`-Identitätsschicht, die genau an dieser einen Naht ansetzt. (`PlaceFolderScanner`
  und `ArchionLinker` folgen separat, sie brauchen zusätzlich relative bzw. Root-Pfade.)

## [1.0.9] – 2026-07-06

### Hinzugefügt
- **Aufgaben tragen jetzt Bearbeiter und Datum.** Eine Orts-Aufgabe (`_tasks.json`)
  speichert beim Anlegen den Anzeigenamen des webtrees-Nutzers und das Erstellungs-
  datum; beide werden neben dem Aufgabentext angezeigt. Damit hat jede Aufgabe einen
  Bezug zu *wer* und *wann* — die zwei Felder, die eine Forschungsaufgabe nach
  GEDCOM-L braucht (`DATE`, `_WT_USER`). Das formt die Sidecar-Aufgabe bereits in die
  Gestalt eines `_TODO`-Eintrags und ist die Vorstufe für einen späteren, opt-in
  `_LOC:_TODO`-Export als Interop-Brücke (Issue #7). Alt-Dateien ohne die Felder
  bleiben unverändert lesbar (Default = leer).

## [1.0.8] – 2026-07-04

### Behoben
- **Das „x" am „Nur Endorte"-Hinweis schloss nicht.** Die Markup war eine
  Bootstrap-`alert-dismissible`-Box, aber ohne `data-bs-dismiss="alert"` — das
  Schließen hing allein an einem eigenen JS-Handler. Jetzt schließt Bootstrap den
  Hinweis nativ (zuverlässig, ohne JS-Abhängigkeit); das Merken (nicht erneut
  zeigen) läuft über eine robuste Event-Delegation + In-Memory-Flag, sodass es
  auch bei blockiertem `localStorage` (Privacy-Modus) sofort wirkt.

### Hinzugefügt
- **„Letzte Zusammenführungen" mit Rückgängig direkt auf der Ortsliste.** Bisher
  war der Undo-Button nur im Merge-Ergebnis-Popup erreichbar — nach dem Schließen
  war er weg, obwohl die Operation (samt Backup) rückrollbar blieb. Jetzt listet
  ein Abschnitt die letzten Merges/Umbenennungen (aus `ortsregister_merge_log`)
  mit Datum, Quelle → Ziel und einem „Rückgängig"-Knopf; bereits zurückgerollte
  Operationen sind markiert. Nutzt den bestehenden, jetzt getesteten Undo-Pfad;
  der Undo-Handler funktioniert dadurch aus Liste **und** Popup. de/en/nl.

### Intern
- **Merge-Sicherheitsnetz jetzt integrationstestbar + getestet.** Die
  datensatz-bezogene Kernlogik von Merge/Undo (PLAC-Ersetzung anwenden,
  Undo-Restore, Stale-Erkennung) hinter eine schmale Naht gezogen
  (`RecordStore`-Interface + `PlaceRecordMutator`); Produktion nutzt
  `WebtreesRecordStore` (dünner Wrapper um `GedcomRecord::updateRecord`),
  Verhalten unverändert. Neuer Integrationstest (`PlaceRecordMutatorTest`, DB-frei
  über In-Memory-Store) belegt die drei bisher ungeprüften Garantien: (1)
  Rollback — ein Schreibfehler mitten im Merge lässt in der Transaktion **keinen**
  Teilzustand zurück; (2) Undo stellt den Vor-Merge-Stand **byte-identisch** her;
  (3) Stale-Schutz meldet fremd geänderte Records → **All-or-Nothing**-Abbruch.

### Geändert
- Toten Code des deaktivierten Koordinaten-Imports entfernt
  (`CoordinateImportPage`): Die abgeschaltete Alpha-Implementierung lag als
  unerreichbarer Code hinter einem early return und zog eine ungenutzte
  Service-Abhängigkeit nach. Entfernt → PHPStan-sauber; die „deaktiviert"-
  Hinweisseite bleibt, ein Rework holt die Logik aus der Git-History.
- Hardcodiertes `customModuleLatestVersion() { return '1.0.0'; }` entfernt — es
  meldete dauerhaft „latest = 1.0.0" und hätte einen Update-Hinweis ohnehin nie
  ausgelöst. webtrees' Trait-Default greift jetzt (gibt die installierte Version
  zurück → Control Panel zeigt sauber „aktuell"). Kein Verhalten für Nutzer
  geändert, nur irreführender toter Code weg.

## [1.0.7] – 2026-07-04

### Behoben (kritisch)
- **Übersichtskarte blieb auf manchen Installationen komplett leer (weißer
  Kasten).** Das Modul lud Leaflet und MarkerCluster von einem externen CDN
  (`unpkg.com`) per `<script>` mitten im Seiteninhalt — das Karten-Script lief
  damit, *bevor* die Bibliothek geladen war, und hatte keinen Rückhalt außer
  dem CDN. Wo `unpkg.com` nicht erreichbar ist (Proxy, restriktive CSP,
  Adblocker, kein Internet am Server), war `L` undefined, `L.map()` warf, und
  die Karte blieb leer. Auf Installationen mit CDN-Zugriff fiel es nicht auf.
  Fix: Das Modul nutzt jetzt das Leaflet-Bundle, das webtrees ohnehin auf jeder
  Seite mitliefert (`vendor.min.js`/`.css`, inkl. MarkerCluster), und hängt das
  Karten-Script über `View::push('javascript')` **hinter** dieses Bundle. Kein
  externes CDN mehr — die Karte funktioniert damit auch offline und unter
  strenger CSP. Das GeoJSON wird direkt ins Script geschrieben statt in ein
  `data`-Attribut, was zugleich die Attribut-Escaping-Problematik aus #5
  (v1.0.5) endgültig gegenstandslos macht. Schließt #5. Danke @TheDutchJewel
  für den beharrlichen Bugreport und die Screenshots.
- **Auch die Detailseiten-Karte lädt Leaflet jetzt aus dem webtrees-Bundle**
  statt von einem CDN (jsdelivr). Sie hatte zwar einen Fallback-Hinweis, hing
  aber am selben externen Risiko — jetzt ebenfalls CDN-frei und CSP-fest.

### Geändert
- **Übersichtskarte nutzt jetzt die Tiles von OSM Deutschland**
  (`tile.openstreetmap.de`) statt `openstreetmap.org` — dieselbe Quelle wie die
  Detailseite, weil `openstreetmap.org` schneller rate-limitet („no access").

## [1.0.6] – 2026-07-04

### Behoben
- **Plural-Badge „%s Orte mit Koordinaten" auf der Kartenseite war nicht
  übersetzbar.** Es ist der einzige echte `I18N::plural()`-String des Moduls
  und fehlte im Übersetzungskatalog (`resources/lang/*.po`) — deshalb zeigte
  das Badge auch bei anderer UI-Sprache den deutschen Quelltext (im NL-UI
  sichtbar neben „zonder coördinaten"). msgid jetzt in `de`/`en`/`nl`
  aufgenommen, NL übersetzt (`%s plaats(en) met coördinaten`), `nl.mo` neu
  kompiliert. Schließt #6 (aus #5 abgespalten). Danke @TheDutchJewel für den
  Hinweis.

## [1.0.5] – 2026-07-04

### Behoben (kritisch)
- **Übersichtskarte zeigte rohes GeoJSON statt der Karte, sobald ein Ortsname
  ein Apostroph enthielt.** Das GeoJSON wurde in ein einfach-quotiertes
  `data-geojson='…'`-Attribut geschrieben; der erste Apostroph in den Daten
  (z. B. niederländische Namen wie `'s-Gravenhage`, `'t Zand`) beendete das
  Attribut vorzeitig, der Rest kippte als Text in die Seite. Da die komplette
  Karte in einem Attribut steckt, brach ein **einziger** betroffener Ort die
  **ganze** Kartenansicht. Fix: Das JSON wird jetzt mit `e()` HTML-escaped
  ausgegeben (`data-geojson="…"`). Der Bug war seit v1.0.0 latent und traf
  jeden Baum mit Apostroph-Ortsnamen (u. a. NL, FR, IT, IE, EN) — bei rein
  deutschen Daten fiel er nur nie auf. Gemeldet aus dem NL-Test
  (@TheDutchJewel). Die Detail-Karte war nicht betroffen (nutzt
  `json_encode` im `<script>`).

## [1.0.4] – 2026-07-04

### Verbessert
- **Niederländische Übersetzung verfeinert** (PR #4 von @TheDutchJewel):
  Beispiele im „Endort"-Hilfetext auf einen für NL-User verständlicheren
  Kontext angepasst. Rein sprachliche Politur, keine Funktionsänderung.

## [1.0.3] – 2026-07-04

### Behoben (kritisch)
- **Modul-UI wurde in KEINER Sprache übersetzt.** Das Modul implementiert nun
  `customTranslations()`, sodass webtrees die msgstrs aus
  `resources/lang/<lang>.mo`/`.po` überhaupt einliest. Bisher gab der
  `ModuleCustomTrait`-Default ein leeres Array zurück — das gesamte UI zeigte
  daher permanent die deutschen msgid-Strings, egal welche Sprache im
  webtrees-Menü gewählt war. Danke an @TheDutchJewel für den detaillierten
  Reproschritt-Bericht ("nu wordt er niets meer vertaald") — der Bug war seit
  v1.0.0 latent und fiel erst durch den v1.0.2-i18n-Fix am Modulnamen auf.

## [1.0.2] – 2026-07-03

### Behoben
- **Modulname wird jetzt lokalisiert dargestellt.** `title()` und `description()`
  gaben literale Strings zurück und riefen `I18N::translate()` nie auf — die
  msgids in `de.po` / `en.po` / `nl.po` griffen also nie. Fix: beide Methoden
  wickeln den String in `I18N::translate()`. Damit erscheint das Modul in
  niederländischen webtrees-Instanzen jetzt als „Plaatsregister" statt
  „Ortsregister" (Reporter: @TheDutchJewel).

### Hinzugefügt
- **Aktualisierte niederländische Übersetzung** (`nl.po`) — PR #2 von
  @TheDutchJewel. `msgid "Ortsregister"` → `msgstr "Plaatsregister"` und
  weitere Feinschliffe.

## [1.0.1] – 2026-07-01

### Behoben / Geändert
- **Koordinaten-Import deaktiviert** (Issue #1, Kritik H. Hartenthaler). Der Import
  schrieb GEDCOM-Koordinaten (`PLAC/MAP/LATI/LONG`) in webtrees' baumübergreifend
  geteilten Orts-Gazetteer (`place_location`). Diese Koordinaten beschreiben aber den
  Ort eines **Ereignisses** (z. B. ein Grab), nicht den Ortsmittelpunkt — webtrees
  hält Ereignis- und Orts-Koordinaten laut eigener Doku (FAQ „locations") **bewusst
  getrennt**; die Vermischung ist konzeptionell falsch. UI-Button + Handler-Einstieg
  gesperrt. Vorhandene Koordinaten wurden nie überschrieben (idempotent + Backup) —
  „zerstört" wurde also nichts, aber der Ansatz war falsch. Rework als „Vorschlag pro
  Ort zur manuellen Übernahme" geplant.

### Dokumentation
- README neu positioniert (Hygiene + Archiv-UX statt „GOV-Modul"), ehrlicher
  Ich-Einstieg („Warum es das gibt") + GEDCOM-Portabilitäts-Hinweise.

## [1.0.0] – 2026-06-30

Erstes stabiles, öffentliches Release. Funktional auf dem Stand der bisherigen
internen Entwicklung (Phasen 1–4), nun als stabil deklariert und mit
Qualitätssicherung abgesichert: statische Analyse (PHPStan, Level 5) und
75 automatisierte Tests.

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
- **Englische + niederländische Lokalisierung**: vollständige `en.po` und `nl.po`
  (je 167 Strings, Deutsch→Englisch bzw. Deutsch→Niederländisch), `de.po` auf den
  aktuellen String-Satz resynct. `msgfmt --check-format` grün für alle (Format-
  Platzhalter konsistent). `I18nService` mappt `nl`/`nl_NL`/`nl_BE` → `nl.po`.
  `.mo` werden zur Laufzeit kompiliert (git-ignored).

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
