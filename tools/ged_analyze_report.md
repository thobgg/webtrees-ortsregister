# GEDCOM-Inventur Report

Erzeugt von `tools/ged_analyze.py`. Anthropic-safe.

## Header (gefiltert)
- **gedcom_version**: `5.5.1`
- **charset**: `UTF-8`
- **source**: `webtrees`
- **source_name**: `webtrees`
- **source_version**: `2.2.5`

## Aggregate
- **records_total**: 3706
- **records_INDI**: 1852
- **records_OBJE**: 731
- **records_FAM**: 657
- **records_SOUR**: 293
- **records__LOC**: 168
- **records_REPO**: 4
- **records_SUBM**: 1
- **plac_occurrences**: 3809
- **plac_unique_values**: 464
- **plac_with_coords**: 2566
- **plac_with_gov**: 0
- **addr_occurrences**: 110

## PLAC-Subtags (Häufigkeit)

| Tag | Anzahl |
|-----|-------:|
| `_LOC` | 2570 |
| `MAP` | 2566 |

## ADDR-Subtags (Häufigkeit)

| Tag | Anzahl |
|-----|-------:|
| `CITY` | 98 |
| `STAE` | 65 |
| `CTRY` | 65 |
| `POST` | 49 |
| `ADR1` | 14 |
| `ADR2` | 1 |

## PLAC-Struktur-Schemata

### Schema P1 — N=2566
```
PLAC <VAL>
  _LOC <VAL>
  MAP <VAL>
    LATI <VAL>
    LONG <VAL>
```
### Schema P2 — N=1239
```
PLAC <VAL>
```
### Schema P3 — N=4
```
PLAC <VAL>
  _LOC <VAL>
```

## ADDR-Struktur-Schemata

### Schema A1 — N=29
```
ADDR <VAL>
  CITY <VAL>
  POST <VAL>
  STAE <VAL>
  CTRY <VAL>
```
### Schema A2 — N=25
```
ADDR <VAL>
  CITY <VAL>
  STAE <VAL>
  CTRY <VAL>
```
### Schema A3 — N=23
```
ADDR <VAL>
  CITY <VAL>
```
### Schema A4 — N=10
```
ADDR <VAL>
  ADR1 <VAL>
  CITY <VAL>
  STAE <VAL>
  POST <VAL>
  CTRY <VAL>
```
### Schema A5 — N=9
```
ADDR <VAL>
```
### Schema A6 — N=9
```
ADDR <VAL>
  CITY <VAL>
  POST <VAL>
```
### Schema A7 — N=3
```
ADDR <VAL>
  ADR1 <VAL>
```
### Schema A8 — N=1
```
ADDR <VAL>
  ADR1 <VAL>
  ADR2 <VAL>
  CITY <VAL>
```
### Schema A9 — N=1
```
ADDR <VAL>
  CITY <VAL>
  STAE <VAL>
  CTRY <VAL>
  POST <VAL>
```

## Custom-Subtag Format-Pattern

Buchstabe→`A`/`a` (Case), Ziffer→`N`, Sonst literal.

### `_LOC`

| Pattern | Anzahl |
|---------|-------:|
| `@AN@` | 1366 |
| `@ANN@` | 977 |
| `@ANNN@` | 227 |

## Dubletten-Heuristik

Schwellwert: Levenshtein ≤ 3 ODER Ähnlichkeit ≥ 0.85 (ganzer Komma-Pfad)

**Cluster gefunden: 40**

_Konkrete Cluster-Inhalte siehe `ged_analyze_full.json` (lokal)._
