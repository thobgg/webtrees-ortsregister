# Ortsregister – webtrees Custom Module

🇬🇧 **English** · [🇩🇪 Deutsch](README.de.md)

**Visual landing page per place with main photo, media linking and (planned) GOV integration.**

| | |
|---|---|
| Module name | `ortsregister` |
| Version | 0.2.0-alpha (alpha — for testers) |
| webtrees | 2.2.x |
| PHP | 8.2 – 8.4 |
| License | GPL-3.0-or-later |

---

## ⚠️ Alpha — for testers

**This module writes to your family tree.** *Merge* and *Rename* rewrite the `PLAC`
of affected records through the native webtrees edit API. Treat it as **alpha**:

- **Make a full GEDCOM backup first** (Control panel → manage trees → export) —
  not just the module's per-operation JSON backup.
- **Test on a copy** of your tree, not your production tree.
- Start with places that have **few events** (large merges run in one transaction
  and are warned about).
- **Spot-check** affected individuals after each operation.
- **Undo** is safe right after an operation; it **aborts** if an affected record
  changed in the meantime — it never overwrites later edits.
- Requires the user preference **“Automatically accept changes”** (operations
  bypass the moderation workflow).
- Found a problem? Please open a **GitHub issue**.

## Idea

webtrees is strictly GEDCOM-conformant and only offers a very plain place
administration. `Ortsregister` adds the **emotional, family-centric view** on places:

- **Visual landing page** per place: main photo, description, gallery
- **Person events** at this place (births, marriages, deaths)
- **Media linking**: associate photos with a place
- **GOV integration** (planned): historical administrative hierarchy, parishes,
  archive links – possibly via the Vesta module

The module **does not compete** with the standard webtrees places module or the
Vesta module family, but focuses on the UX layer on top of them.

## Feature list (current state)

- List view of all places with server-side DataTables pagination
- **Hierarchy filter** (see below): "All levels" vs. "Leaves only"
- Full-text filter
- Leaflet map with MarkerCluster
- Place detail page with person/family lists
- **Merge operation** with JSON backup, opaque subtag handling, suffix-match
  across intermediate hierarchy levels

### Hierarchy filter

webtrees splits PLAC strings at commas and stores **one record per level**.
From `Weiler, Amt Kirchheim, Hzm. Württemberg` you get three records (Weiler,
Amt Kirchheim, Hzm. Württemberg). Administrative middle levels like „Amt
Kirchheim" are not real places, but appear in the default list.

**"Leaves only" mode** hides any hierarchy level that has place children.
Only the leaves remain — typically the real localities. Persists per user
(user preference).

Default: "All levels" (no data-loss feeling, conservative). Toggle above the
list.

## Roadmap

| Step | Content |
|---|---|
| 1 | Own data model: `ortsregister_ort`, `ortsregister_ort_medium` |
| 2 | Photo linking: 📍 button in lightbox "Assign to this place" |
| 3 | Visual landing page with main photo and gallery |
| 4 | GOV integration (standalone or via Vesta API) |

## Requirements

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

Then activate in webtrees under **Control Panel → Modules → Custom Modules**.

## Architecture

```
ortsregister/
├── module.php                       ← webtrees entry point
├── composer.json
├── src/
│   ├── OrtsregisterModule.php      ← Main module class
│   ├── Cache/                       ← APCu cache
│   ├── Dto/OrtDto.php              ← Place DTO
│   ├── Http/RequestHandlers/        ← List, detail, map
│   ├── Repository/OrteRepository.php← DB access
│   └── Service/I18nService.php     ← Translation helper
├── resources/
│   ├── menu-icon.png               ← Menu icon (transparent on-the-fly)
│   ├── lang/de.po, en.po, nl.po    ← Translations (de + en + nl)
│   └── views/                       ← orte.phtml, orte-karte.phtml, ort-detail.phtml
└── docs/                            ← (later) screenshots and docs
```

## Routing

| Route name | URL | Method |
|---|---|---|
| `ortsregister.orte` | `/tree/{tree}/orte` | GET |
| `ortsregister.orte.data` | `/tree/{tree}/orte/data` | GET (JSON) |
| `ortsregister.orte.karte` | `/tree/{tree}/orte/karte` | GET |
| `ortsregister.orte.detail` | `/tree/{tree}/orte/{place_id}` | GET |

## Relation to other modules

| Module | Function | Recommendation |
|---|---|---|
| **webtrees Core** | Standard place management (GEDCOM) | Stays active |
| **Vesta Gov4Webtrees** | Fetches and caches GOV data | Optional, can run in parallel |

## Localisation

The UI ships with **German, English and Dutch** (`resources/lang/de.po`, `en.po`,
`nl.po`, 167 strings each). webtrees picks the catalogue matching the user's
language; `.mo` files are compiled on demand at runtime (and are git-ignored).

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).

## Author

Thomas Bugge · thomas@bgg-mail.de
