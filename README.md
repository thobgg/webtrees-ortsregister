# Ortsregister – webtrees Custom Module

🇬🇧 **English** · [🇩🇪 Deutsch](README.de.md)

**Visual landing page per place with main photo, media linking and (planned) GOV integration.**

| | |
|---|---|
| Module name | `ortsregister` |
| Version | 0.1.0 (in development) |
| webtrees | 2.2.x |
| PHP | 8.2 – 8.4 |
| License | GPL-3.0-or-later |

---

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
- Full-text filter
- Leaflet map with MarkerCluster
- Place detail page with person/family lists

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
│   ├── lang/de.po, de.mo           ← Translations
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

The UI is currently in **German**. An English translation (`en.po`) is on the roadmap.

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).

## Author

Thomas Bugge · thomas@bgg-mail.de
