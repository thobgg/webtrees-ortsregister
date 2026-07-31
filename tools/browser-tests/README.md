# Browser-Tests für das Notizen-Modal

Die PHPUnit-Suite fasst kein JavaScript an. Diese Skripte schließen die Lücke für
den Teil, der am ehesten still kaputtgeht: das Zusammenspiel des Notizen-Modals
mit dem visuellen Markdown-Editor aus dem Fremdmodul
[linkenhancer](https://codeberg.org/bschwede/linkenhancer).

Sie sind ein **Werkzeug, keine Testsuite**: Sie brauchen eine echte
webtrees-Installation samt installiertem linkenhancer und laden dessen
ausgeliefertes JavaScript. Deshalb laufen sie nicht bei jedem Commit mit — man
ruft sie auf, wenn man am Modal oder am Editor etwas ändert, oder wenn eine neue
linkenhancer-Version erscheint.

Geprüft wird der **echte** Code: `build-harness.mjs` schneidet den `<script>`-Block
direkt aus `resources/views/ort-detail.phtml` aus und baut ihn in eine Testseite
mit nachgebautem Modal-Markup. Eine Abschrift des JavaScripts gibt es hier
bewusst nicht, sonst prüft man am Ende die Kopie statt der Auslieferung.

## Einrichten

```
cd tools/browser-tests
npm install
npx playwright-core install chromium      # rund 115 MB, einmalig
```

## Ausführen

```
npm test
```

Die Pfade werden vom Speicherort aus hergeleitet und passen, solange das Modul
unter `<webtrees>/modules_v4/ortsregister/` liegt. Sonst überschreiben:

```
WEBTREES_ROOT=/pfad/zu/webtrees LINKENHANCER=/pfad/zu/linkenhancer/resources npm test
```

## Was drin ist

**`test-person-picker.mjs`** — „Person einfügen" muss den `[Name](indi:Xxx)`-Link
an der Cursorstelle einfügen, sichtbar, und er muss weiteres Tippen und das
Speichern überleben. Geprüft am Zeilenende, mitten im Text, ohne vorherigen Klick,
zweimal hintereinander und mit abgeschaltetem Editor.

**`test-editor-sync.mjs`** — alle neun Knöpfe der Werkzeugleiste müssen ihre
Änderung in die versteckte Textarea zurückschreiben, aus der beim Speichern
gelesen wird. Zusätzlich der Weg über die Tastenkombination Strg+K, der den
Klick-Handler umgeht.

## Wenn linkenhancer nachbessert

`test-editor-sync.mjs` ist zugleich die Prüfung für
[linkenhancer#118](https://codeberg.org/bschwede/linkenhancer/issues/118).
Sobald dort behoben, lässt sich die Notbremse
(`ortsregisterSyncMarkdownEditors` in `ort-detail.phtml`) probeweise entfernen:
Meldet die Knopf-Tabelle danach immer noch überall „zieht mit", kann sie raus.
Dann aber die Mindestversion von linkenhancer in `README.md`, `README.de.md` und
im Hinweistext der Modul-Einstellungen anheben — sonst verlieren Nutzer älterer
Versionen wieder still ihre Eingaben.
