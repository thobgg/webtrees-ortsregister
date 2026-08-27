# Werkzeuge

Kleine Skripte zum Nachsehen, was die **echte** Instanz tut. Sie gehören nicht
zum Modul und werden nie ausgeliefert – Tests prüfen, ob der Code das Richtige
tut, diese Skripte prüfen, ob die Daten das Erwartete hergeben. Zwei
verschiedene Fragen.

Alle laufen **nur in der Kommandozeile** und **nur lesend**. Über den Webserver
aufgerufen antworten sie mit 404: sie liegen in einem öffentlichen Repo und
öffnen eine Datenbankverbindung mit den Rechten der Instanz, das gehört nicht
ins Netz. Zugangsdaten stehen in keinem der Skripte – sie werden zur Laufzeit
aus `data/config.ini.php` gelesen und nie ausgegeben.

Aufruf immer aus dem webtrees-Verzeichnis, mit einem PHP, das `pdo_mysql` oder
`mysqli` mitbringt:

```bash
php modules_v4/ortsregister/tools/<skript>.php
```

| Skript | beantwortet |
|---|---|
| `loc_live_check.php` | Liest der `_LOC`-Leser aus der echten Datenbank das, was er soll? Beispiellauf über den ersten gefundenen `_LOC`-Namen. |
| `loc_coord_compare.php` | Welche Orte verlieren durch die hierarchie-genaue Lesart ihre Koordinaten? Vergleicht alt (Blattname) gegen neu (Vollpfad) über alle Orte. |
| `loc_coord_debug.php` | Dasselbe für einen einzelnen Ort, mit Zwischenschritten. |
| `loc_record_dump.php` | Gibt einen `_LOC`-Datensatz roh aus – zum Nachsehen, was im GEDCOM wirklich steht. |
| `gov_object_dump.php` | Rohe Antwort der GOV-Schnittstelle zu einer Kennung. Nur Netz, keine Datenbank. Beantwortet unter anderem, ob `part-of` mit Zeitspannen geliefert wird. |
| `ged_analyze.py` | Auswertung einer GEDCOM-Datei; schreibt `ged_analyze_report.md`. |

`browser-tests/` ist eigenständig – Playwright-Tests für den Editor und die
Personenauswahl, mit eigener `README.md`. Deren `node_modules` liegen nur
lokal und stehen in `.gitignore`.
