# Ortsregister – webtrees Custom Module

🇬🇧 **English** · [🇩🇪 Deutsch](README.de.md)

**Your family archive in webtrees, organised by place.** Every place in the tree becomes an archive page with photos, church books, sources, a map and notes. Plus tools to keep the places themselves tidy (merge and rename with preview, backup and undo).

| | |
|---|---|
| Module name | `ortsregister` |
| Version | 1.0.6 |
| webtrees | 2.2.x |
| PHP | 8.2 – 8.4 |
| License | GPL-3.0-or-later |

---

## ⚠️ Before you start — this module writes to your tree

**This module writes to your family tree.** *Merge* and *Rename* rewrite the `PLAC`
of affected records through the native webtrees edit API. Because it changes records,
take the usual precautions:

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

## Why it exists

I wanted my family archive inside webtrees, not next to it. My research used to live in Ahnenblatt or Gramps, and the photos, documents and digitised records somewhere else. webtrees manages the tree cleanly and comes with a well-designed permission system. It has media management too, but that only shows what is entered as a media object in the GEDCOM.

Ortsregister organises the archive by place. The files sit in folders and are shown without each one having to be imported into the tree first. Every place becomes a page that gathers what you have about it: photos, church books, sources, a map, notes, a research log. Plus tools to keep the places tidy, that is merging spelling variants or renaming, with preview and backup.

Everything stays in open formats, as a file in the folder or in the tree. The database is just an index. For me this is a platform that is mine and that I can grow into whenever I have more time for research again. The sister module Sammlungen does the same, only organised by theme instead of by place.

## Screenshots

A place archive page — GOV-anchored hierarchy, event counts, external sources, notes and gallery in one view:

![Ortsregister place archive page](docs/images/ortsregister-landing.png)

Merge places safely — source → target with the affected-record count, warnings and an automatic JSON backup before anything is written:

![Ortsregister merge dialog](docs/images/ortsregister-merge.png)

A per-place research log in Markdown, with task lists and one-click links to individuals in the tree:

![Ortsregister research log editor](docs/images/ortsregister-research-log.png)

Leaflet / OpenStreetMap view, the place located from its coordinates:

![Ortsregister map view](docs/images/ortsregister-place-map.png)

## Feature list (current state)

- Place list (server-side DataTables pagination, full-text filter)
- **Hierarchy filter**: "All levels" vs. "Leaves only" (see below)
- **GOV status column** + per-place GOV linking, GOV hierarchy on the detail page
- Leaflet map with MarkerCluster
- **Detail page** per place: event statistics (births/marriages/deaths), media gallery
  with lightbox, notes/tasks/church-book log (Markdown)
- **Place hygiene**: merge & rename with preview, JSON backup and undo; curated data
  (notes/church books/GOV/digitised items) travels along
- **External hits** on the detail page: Wikimedia/Commons, German Digital Library,
  Archion auto parish lookup

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

## Known limitations

- **Same-named places share one data folder.** Two "Neustadt" on different hierarchy
  levels use the same `media/orte/Neustadt/` folder. Merge/rename warns in this case
  and skips the folder operation.
- **Filesystem operations are not transactional.** If an operation aborts after the
  GEDCOM change but during the folder move, the sidecar layer can be left inconsistent
  (DB/GEDCOM roll back cleanly).
- **Auto-accept required.** Merge/rename need "Automatically accept changes" and bypass
  the moderation / pending-changes workflow.
- **Very large merges** (e.g. whole countries) run in a single transaction without
  batching — thousands of records risk timeout/memory (you are warned).
- **Undo** is safe right after an operation; if a record changed since, it aborts
  (overwrites nothing) — not a full version history.
- **Curated archive data is not stored in the GEDCOM.** Notes, church-book logs, tasks
  and digitised items live as files in the place folder (`media/orte/…`) plus a DB index —
  **not** in the tree. A GEDCOM export therefore does not carry them; back up the place
  folder separately. (Deliberate choice: open, readable files instead of proprietary
  GEDCOM extensions.)

## Roadmap

Current feature state is in the [CHANGELOG](CHANGELOG.md). Feedback is welcome; next up:
record-level split (detaching single events from a collective place)
and expanding duplicate detection.

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
└── docs/images/                     ← screenshots for this README
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
