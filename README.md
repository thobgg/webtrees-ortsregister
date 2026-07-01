# Ortsregister – webtrees Custom Module

🇬🇧 **English** · [🇩🇪 Deutsch](README.de.md)

**🏡 Your growing place archive for webtrees.** Every place in your family tree becomes
an archive page that grows richer with each piece of research: digitised records, church
books, sources, maps and a research log in one place.

🧹 Safe place hygiene — merge spelling variants with preview · backup · undo · 🗂️ every place an archive page · 🌍 GOV as a reference layer · 🔓 open formats, no lock-in.

| | |
|---|---|
| Module name | `ortsregister` |
| Version | 1.0.0 |
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

## What it does

**Every place in your family tree becomes an archive page that grows richer with each piece of research.** Whatever you gather about a village, a parish, a town collects in one place — permanent, ordered, findable.

🗂️ **Your archive gets a home.** Digitised records, church books, sources, maps, notes and your research log — right where you look for them. No more finds scattered across folders, e-mails and sticky notes.

🧹 **So your archive stays cleanly addressed.** “Brackenheim”, “Brakenheim”, “Brackenheim a.N.” — same place, three spellings? Ortsregister recognises places that belong together and merges them safely: with preview, full backup and undo. Your curated data travels along — no note, no scan is lost.

🌍 **Watertight anchoring.** The GOV link connects your places to the official historical gazetteer: stable identifier, historical name variants, hierarchy and coordinates. Plus hits from Archion, the German Digital Library and Wikimedia, right on the place page.

🔎 **Shows you where the work is.** Which places still lack coordinates? No GOV ID? Which look like duplicates? Ortsregister turns your place list into a work queue.

🔓 **Your data stays your data.** Everything lives in the open standard of its kind — in the tree or as a readable file in the place folder. The database is just an index, rebuildable any time. No proprietary format, no vendor lock-in.

**This is not "another GOV module."** GOV data is also provided by the Vesta family — here it is just one reference layer. Ortsregister's distinct value is the **place-as-archive-page and the safe merge/rename hygiene**: the UX and archive layer on top of webtrees' places, not a gazetteer. It complements the standard places module and Vesta rather than competing with them.

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
