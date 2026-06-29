# Drittdaten in `resources/data/`

## `archion-parishes.json`

Indizierter Datensatz aller evangelischen Pfarreien Deutschlands, die im
Webdienst **Archion** (archion.de) gelistet sind. Enthält pro Pfarrei
Name, Archiv, Dekanat, relativen Archion-Pfad und Koordinaten.

**Quelle:** [github.com/brger93/archionkarte-20](https://github.com/brger93/archionkarte-20)
**Lizenz der Quelle:** MIT (Copyright © brger93)
**Daten-Stand:** Snapshot der GeoJSON-Files unter `data/geojson_sorted/`
**Verarbeitung:** Felder gestrippt auf `n`, `ai`, `di`, `p`, `g` plus
zentrale `archives[]` / `districts[]`-Indices, JSON minified.

### Update-Strategie

Manuell bei Modul-Release: `tools/update-archion-data.sh` (Skript folgt)
oder direkt:

```bash
# auf der NAS
curl -sL https://github.com/brger93/archionkarte-20/archive/refs/heads/main.tar.gz \
    | tar xz -C /tmp/ archionkarte-20-main/data/geojson_sorted/
# ... dann via Python wie in der ursprünglichen Build-Session: stripe/index/minify
```

### Format

```json
{
  "_meta":     { "source": "...", "license": "MIT" },
  "archives":  ["Ev. Landeskirche Anhalts", "Ev. Landeskirche in Bayern", ...],
  "districts": ["Dekanat Aalen", "Kirchenkreis Anhalt-Bernburg", ...],
  "parishes":  [
    { "n": "Aalen", "ai": 5, "di": 0, "p": "/de/...", "g": [10.09, 48.84] },
    ...
  ]
}
```

`ai`/`di` sind Indices in die `archives`/`districts`-Arrays.
