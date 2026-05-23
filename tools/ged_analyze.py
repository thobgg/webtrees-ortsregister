#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GEDCOM-Inventur für PLAC/ADDR-Strukturen.

Liest ein GEDCOM-File hierarchie-bewusst (Stack-Parser, Level 0/1/2/...),
erfasst NUR ortsbezogene Strukturen (PLAC + Subtree, ADDR + Subtree) und
schreibt zwei Outputs:

  --report PATH   (default tools/ged_analyze_report.md)
      Anthropic-safe Aggregat-Report: Tag-Häufigkeiten, Struktur-Schemata
      mit Platzhaltern, Format-Pattern für _GOV/_LOC, Dubletten-Cluster-
      Anzahl. Keine konkreten Ortsnamen, IDs, Koordinaten. Teilbar.

  --full-out PATH (default tools/ged_analyze_full.json)
      Konkrete Werte für Thomas zur Merge-Vorbereitung: alle PLAC-Strings
      + Häufigkeit, alle _GOV-IDs, alle Koordinaten, Dubletten-Cluster
      konkret, Beispiel-Record-Pointer pro Schema. Lokal, NICHT teilbar.

Nur Standardbibliothek. Idempotent. Forward-compatible: Tags werden
opak erfasst, keine semantische Bewertung.

Dubletten-Heuristik: Levenshtein-Distanz <= 3 ODER Ähnlichkeit >= 0.85
auf dem vollen Komma-Pfad. Union-Find clustering.

Beispiel:
  python3 tools/ged_analyze.py /mnt/NAS_shared/webtrees/data/tree1.ged
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

# ---------------------------------------------------------------
# GEDCOM-Encoding-Erkennung
# ---------------------------------------------------------------

def detect_encoding(path: Path) -> str:
    """Liest Header-Bytes und erkennt CHAR-Deklaration.

    Reihenfolge: BOM → 1 CHAR <name> → Fallback UTF-8.
    Mapping CHAR-Werte → Python-Codecs:
        UTF-8/UTF8       → utf-8
        ANSI/WINDOWS-1252→ cp1252
        ASCII            → ascii
        ANSEL            → latin-1 (Best-Effort, keine offizielle Konvertierung)
    """
    raw = path.read_bytes()[:4096]
    if raw.startswith(b"\xef\xbb\xbf"):
        return "utf-8-sig"

    # Heuristisches Lesen ohne harte Encoding-Annahme
    try:
        head = raw.decode("utf-8", errors="replace")
    except Exception:
        head = raw.decode("latin-1", errors="replace")

    m = re.search(r"^\s*1\s+CHAR\s+(\S+)", head, re.MULTILINE)
    if m:
        char = m.group(1).upper()
        return {
            "UTF-8": "utf-8",
            "UTF8": "utf-8",
            "ANSI": "cp1252",
            "WINDOWS-1252": "cp1252",
            "ASCII": "ascii",
            "ANSEL": "latin-1",
        }.get(char, "utf-8")
    return "utf-8"

# ---------------------------------------------------------------
# Levenshtein (iterativ, O(m*n) Zeit, O(min(m,n)) Speicher)
# ---------------------------------------------------------------

def levenshtein(a: str, b: str) -> int:
    if a == b:
        return 0
    if len(a) < len(b):
        a, b = b, a
    if not b:
        return len(a)
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        cur = [i] + [0] * len(b)
        for j, cb in enumerate(b, 1):
            cur[j] = min(
                cur[j - 1] + 1,
                prev[j] + 1,
                prev[j - 1] + (0 if ca == cb else 1),
            )
        prev = cur
    return prev[-1]


def similarity(a: str, b: str, dist: int) -> float:
    longest = max(len(a), len(b))
    return 1.0 - (dist / longest) if longest else 1.0

# ---------------------------------------------------------------
# Union-Find für Dublettencluster
# ---------------------------------------------------------------

class UnionFind:
    def __init__(self, n: int) -> None:
        self.parent = list(range(n))

    def find(self, x: int) -> int:
        while self.parent[x] != x:
            self.parent[x] = self.parent[self.parent[x]]
            x = self.parent[x]
        return x

    def union(self, a: int, b: int) -> None:
        ra, rb = self.find(a), self.find(b)
        if ra != rb:
            self.parent[rb] = ra

    def groups(self) -> list[list[int]]:
        buckets: dict[int, list[int]] = defaultdict(list)
        for i in range(len(self.parent)):
            buckets[self.find(i)].append(i)
        return [g for g in buckets.values() if len(g) > 1]

# ---------------------------------------------------------------
# GEDCOM-Stack-Parser
# ---------------------------------------------------------------

LINE_RE = re.compile(r"^\s*(\d+)\s+(?:(@[^@]+@)\s+)?(\S+)(?:\s(.*))?$")


class GedLine:
    __slots__ = ("level", "xref", "tag", "value", "lineno")

    def __init__(self, level: int, xref: str | None, tag: str, value: str, lineno: int) -> None:
        self.level = level
        self.xref = xref
        self.tag = tag
        self.value = value
        self.lineno = lineno


def parse_lines(text: str) -> list[GedLine]:
    out: list[GedLine] = []
    for lineno, raw in enumerate(text.splitlines(), 1):
        if not raw.strip():
            continue
        m = LINE_RE.match(raw)
        if not m:
            # Unparsbare Zeile überspringen (z.B. UTF-8 BOM-Rest)
            continue
        lvl, xref, tag, value = m.groups()
        out.append(GedLine(int(lvl), xref, tag, value or "", lineno))
    return out


def fold_cont_conc(lines: list[GedLine]) -> list[GedLine]:
    """Hängt CONT (Newline) und CONC (Inline) an die jeweilige Vater-Zeile."""
    folded: list[GedLine] = []
    for ln in lines:
        if ln.tag in ("CONT", "CONC") and folded:
            sep = "\n" if ln.tag == "CONT" else ""
            folded[-1].value += sep + ln.value
            continue
        folded.append(ln)
    return folded

# ---------------------------------------------------------------
# Subtree-Extraktion: pro PLAC/ADDR-Block Subtree als
# Liste von (rel_level, tag, value) sammeln
# ---------------------------------------------------------------

class Subtree:
    __slots__ = ("anchor_tag", "value", "subs", "owner_xref", "owner_type", "context_path")

    def __init__(self, anchor_tag: str, value: str, owner_xref: str | None,
                 owner_type: str | None, context_path: tuple[str, ...]) -> None:
        self.anchor_tag = anchor_tag        # "PLAC" oder "ADDR"
        self.value = value
        self.subs: list[tuple[int, str, str]] = []   # (rel_level, tag, value)
        self.owner_xref = owner_xref
        self.owner_type = owner_type
        self.context_path = context_path    # ("INDI","BIRT") z.B.


def extract_subtrees(lines: list[GedLine]) -> list[Subtree]:
    """Geht durch das gefoldete File und schneidet pro PLAC/ADDR den Subtree."""
    subtrees: list[Subtree] = []
    tag_stack: list[str] = []           # Tag-Pfad parallel zum Level
    record_xref: str | None = None
    record_type: str | None = None
    i = 0
    n = len(lines)
    while i < n:
        ln = lines[i]
        # Stack auf aktuelles Level bringen
        del tag_stack[ln.level:]
        tag_stack.append(ln.tag)

        # Record-Anker mitschneiden
        if ln.level == 0:
            record_xref = ln.xref
            record_type = ln.tag

        if ln.tag in ("PLAC", "ADDR"):
            context_path = tuple(tag_stack[1:-1])   # Tags zwischen Record und PLAC/ADDR
            st = Subtree(ln.tag, ln.value, record_xref, record_type, context_path)
            anchor_level = ln.level
            j = i + 1
            while j < n and lines[j].level > anchor_level:
                rel = lines[j].level - anchor_level
                st.subs.append((rel, lines[j].tag, lines[j].value))
                j += 1
            subtrees.append(st)
            i = j
            continue
        i += 1
    return subtrees

# ---------------------------------------------------------------
# Header-Extraktion (für beide Outputs unterschiedlich gefiltert)
# ---------------------------------------------------------------

def extract_header(lines: list[GedLine]) -> dict[str, str]:
    """Greift relevante Header-Felder ab: GEDC.VERS, CHAR, SOUR, SOUR.VERS, DEST."""
    header: dict[str, str] = {}
    in_head = False
    in_sour = False
    in_gedc = False
    for ln in lines:
        if ln.level == 0:
            if ln.tag == "HEAD":
                in_head = True
            else:
                break
            continue
        if not in_head:
            continue
        if ln.level == 1:
            in_sour = ln.tag == "SOUR"
            in_gedc = ln.tag == "GEDC"
            if ln.tag == "SOUR":
                header["source"] = ln.value
            elif ln.tag == "CHAR":
                header["charset"] = ln.value
            elif ln.tag == "DEST":
                header["dest"] = ln.value
        elif ln.level == 2:
            if in_sour and ln.tag == "VERS":
                header["source_version"] = ln.value
            elif in_sour and ln.tag == "NAME":
                header["source_name"] = ln.value
            elif in_gedc and ln.tag == "VERS":
                header["gedcom_version"] = ln.value
    return header

# ---------------------------------------------------------------
# Format-Pattern für IDs/Werte (für Custom-Tags wie _GOV, _LOC)
# ---------------------------------------------------------------

def format_pattern(value: str) -> str:
    out: list[str] = []
    for ch in value:
        if ch.isupper():
            out.append("A")
        elif ch.islower():
            out.append("a")
        elif ch.isdigit():
            out.append("N")
        else:
            out.append(ch)
    return "".join(out)

# ---------------------------------------------------------------
# Schema-Repräsentation: tuple aus (rel_level, tag) — ohne Wert
# ---------------------------------------------------------------

def subtree_schema(st: Subtree) -> tuple[tuple[int, str], ...]:
    return tuple((rel, tag) for rel, tag, _ in st.subs)

# ---------------------------------------------------------------
# Aufbereitung der Ergebnisse
# ---------------------------------------------------------------

def render_schema(anchor_tag: str, schema: tuple[tuple[int, str], ...]) -> list[str]:
    lines = [f"{anchor_tag} <VAL>"]
    for rel, tag in schema:
        indent = "  " * rel
        lines.append(f"{indent}{tag} <VAL>")
    return lines

# ---------------------------------------------------------------
# Dublettenanalyse
# ---------------------------------------------------------------

def find_duplicates(unique_plac: list[str],
                    max_dist: int = 3,
                    min_sim: float = 0.85) -> list[list[str]]:
    n = len(unique_plac)
    uf = UnionFind(n)
    # O(n²) — bei wenigen tausend ok
    for i in range(n):
        for j in range(i + 1, n):
            a, b = unique_plac[i], unique_plac[j]
            # Frühabbruch wenn Längendifferenz schon zu groß
            if abs(len(a) - len(b)) > max_dist and \
               similarity(a, b, abs(len(a) - len(b))) < min_sim:
                continue
            d = levenshtein(a, b)
            if d <= max_dist or similarity(a, b, d) >= min_sim:
                uf.union(i, j)
    return [[unique_plac[i] for i in g] for g in uf.groups()]

# ---------------------------------------------------------------
# Report-Builder
# ---------------------------------------------------------------

def build_report(header: dict[str, str],
                 stats: dict[str, int],
                 plac_subtag_counts: Counter,
                 addr_subtag_counts: Counter,
                 plac_schemata: list[tuple[tuple[tuple[int, str], ...], int]],
                 addr_schemata: list[tuple[tuple[tuple[int, str], ...], int]],
                 pattern_counts: dict[str, Counter],
                 dup_clusters_count: int,
                 dup_threshold_desc: str) -> str:
    out: list[str] = []
    out.append(f"# GEDCOM-Inventur Report")
    out.append("")
    out.append(f"Erzeugt von `tools/ged_analyze.py`. Anthropic-safe.")
    out.append("")

    out.append("## Header (gefiltert)")
    safe_keys = ("gedcom_version", "charset", "source", "source_name", "source_version")
    for k in safe_keys:
        if k in header:
            out.append(f"- **{k}**: `{header[k]}`")
    out.append("")

    out.append("## Aggregate")
    for k, v in stats.items():
        out.append(f"- **{k}**: {v}")
    out.append("")

    out.append("## PLAC-Subtags (Häufigkeit)")
    out.append("")
    out.append("| Tag | Anzahl |")
    out.append("|-----|-------:|")
    for tag, c in plac_subtag_counts.most_common():
        out.append(f"| `{tag}` | {c} |")
    out.append("")

    out.append("## ADDR-Subtags (Häufigkeit)")
    out.append("")
    if addr_subtag_counts:
        out.append("| Tag | Anzahl |")
        out.append("|-----|-------:|")
        for tag, c in addr_subtag_counts.most_common():
            out.append(f"| `{tag}` | {c} |")
    else:
        out.append("_keine ADDR-Vorkommen_")
    out.append("")

    out.append("## PLAC-Struktur-Schemata")
    out.append("")
    for idx, (schema, count) in enumerate(plac_schemata, 1):
        out.append(f"### Schema P{idx} — N={count}")
        out.append("```")
        for line in render_schema("PLAC", schema):
            out.append(line)
        out.append("```")
    out.append("")

    if addr_schemata:
        out.append("## ADDR-Struktur-Schemata")
        out.append("")
        for idx, (schema, count) in enumerate(addr_schemata, 1):
            out.append(f"### Schema A{idx} — N={count}")
            out.append("```")
            for line in render_schema("ADDR", schema):
                out.append(line)
            out.append("```")
        out.append("")

    if pattern_counts:
        out.append("## Custom-Subtag Format-Pattern")
        out.append("")
        out.append("Buchstabe→`A`/`a` (Case), Ziffer→`N`, Sonst literal.")
        out.append("")
        for tag in sorted(pattern_counts):
            out.append(f"### `{tag}`")
            out.append("")
            out.append("| Pattern | Anzahl |")
            out.append("|---------|-------:|")
            for pat, c in pattern_counts[tag].most_common():
                out.append(f"| `{pat}` | {c} |")
            out.append("")

    out.append("## Dubletten-Heuristik")
    out.append("")
    out.append(f"Schwellwert: {dup_threshold_desc}")
    out.append("")
    out.append(f"**Cluster gefunden: {dup_clusters_count}**")
    out.append("")
    out.append("_Konkrete Cluster-Inhalte siehe `ged_analyze_full.json` (lokal)._")
    out.append("")

    return "\n".join(out)

# ---------------------------------------------------------------
# Main
# ---------------------------------------------------------------

def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="GEDCOM-Inventur für PLAC/ADDR")
    ap.add_argument("gedfile", type=Path, help="Pfad zum GEDCOM-File")
    ap.add_argument("--report", type=Path,
                    default=Path("tools/ged_analyze_report.md"))
    ap.add_argument("--full-out", type=Path,
                    default=Path("tools/ged_analyze_full.json"))
    ap.add_argument("--top-schemata", type=int, default=20,
                    help="Wieviele Schemata im Report listen (Default 20)")
    ap.add_argument("--max-dist", type=int, default=3,
                    help="Levenshtein-Maximum für Dubletten (Default 3)")
    ap.add_argument("--min-sim", type=float, default=0.85,
                    help="Ähnlichkeits-Minimum für Dubletten (Default 0.85)")
    args = ap.parse_args(argv)

    if not args.gedfile.exists():
        print(f"ERROR: {args.gedfile} nicht gefunden", file=sys.stderr)
        return 1

    encoding = detect_encoding(args.gedfile)
    text = args.gedfile.read_text(encoding=encoding, errors="replace")
    lines = fold_cont_conc(parse_lines(text))

    header = extract_header(lines)
    header["detected_encoding"] = encoding

    # Record-Zähler nach Typ
    record_types: Counter = Counter()
    for ln in lines:
        if ln.level == 0 and ln.tag not in ("HEAD", "TRLR"):
            record_types[ln.tag] += 1

    subtrees = extract_subtrees(lines)
    plac_sts = [s for s in subtrees if s.anchor_tag == "PLAC"]
    addr_sts = [s for s in subtrees if s.anchor_tag == "ADDR"]

    # PLAC-Wert-Statistik
    plac_value_counts: Counter = Counter(s.value for s in plac_sts if s.value)
    unique_plac = sorted(plac_value_counts.keys())

    # Subtag-Häufigkeiten — direkte Kinder (rel_level == 1)
    def subtag_counter(sts: list[Subtree]) -> Counter:
        c: Counter = Counter()
        for s in sts:
            for rel, tag, _ in s.subs:
                if rel == 1:
                    c[tag] += 1
        return c

    plac_subtag_counts = subtag_counter(plac_sts)
    addr_subtag_counts = subtag_counter(addr_sts)

    # Schemata
    plac_schema_counts: Counter = Counter(subtree_schema(s) for s in plac_sts)
    addr_schema_counts: Counter = Counter(subtree_schema(s) for s in addr_sts)
    plac_schemata = plac_schema_counts.most_common(args.top_schemata)
    addr_schemata = addr_schema_counts.most_common(args.top_schemata)

    # Format-Pattern für _GOV / _LOC (alle direkten Custom-Subtag-Werte)
    pattern_counts: dict[str, Counter] = defaultdict(Counter)
    custom_value_collect: dict[str, list[str]] = defaultdict(list)
    coords: list[tuple[float, float]] = []
    for s in plac_sts:
        for rel, tag, val in s.subs:
            if rel == 1 and tag in ("_GOV", "_LOC") and val:
                pattern_counts[tag][format_pattern(val)] += 1
                custom_value_collect[tag].append(val)
        # Koordinaten einsammeln (MAP/LATI + MAP/LONG)
        lat = lon = None
        for rel, tag, val in s.subs:
            if rel == 2 and tag == "LATI":
                lat = val
            elif rel == 2 and tag == "LONG":
                lon = val
        if lat and lon:
            try:
                coords.append((_parse_geo(lat), _parse_geo(lon)))
            except ValueError:
                pass

    # Stats
    n_plac_with_coords = sum(
        1 for s in plac_sts
        if any(t == "LATI" for _, t, _ in s.subs) and any(t == "LONG" for _, t, _ in s.subs)
    )
    n_plac_with_gov = sum(
        1 for s in plac_sts
        if any(t == "_GOV" for r, t, _ in s.subs if r == 1)
    )
    stats = {
        "records_total": sum(record_types.values()),
        **{f"records_{k}": v for k, v in record_types.most_common()},
        "plac_occurrences": len(plac_sts),
        "plac_unique_values": len(unique_plac),
        "plac_with_coords": n_plac_with_coords,
        "plac_with_gov": n_plac_with_gov,
        "addr_occurrences": len(addr_sts),
    }

    # Dubletten
    dup_clusters = find_duplicates(unique_plac, args.max_dist, args.min_sim)
    dup_threshold_desc = f"Levenshtein ≤ {args.max_dist} ODER Ähnlichkeit ≥ {args.min_sim:.2f} (ganzer Komma-Pfad)"

    report = build_report(
        header, stats,
        plac_subtag_counts, addr_subtag_counts,
        plac_schemata, addr_schemata,
        pattern_counts,
        len(dup_clusters),
        dup_threshold_desc,
    )

    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(report, encoding="utf-8")

    # Full JSON
    full = {
        "header": header,
        "stats": stats,
        "record_types": dict(record_types),
        "plac_value_counts": dict(plac_value_counts),
        "plac_subtag_counts": dict(plac_subtag_counts),
        "addr_subtag_counts": dict(addr_subtag_counts),
        "plac_schemata": [
            {
                "shape": [list(t) for t in schema],
                "count": count,
                "example_owner_xref": _find_example(plac_sts, schema),
            }
            for schema, count in plac_schemata
        ],
        "addr_schemata": [
            {
                "shape": [list(t) for t in schema],
                "count": count,
                "example_owner_xref": _find_example(addr_sts, schema),
            }
            for schema, count in addr_schemata
        ],
        "custom_subtag_values": {k: Counter(v).most_common() for k, v in custom_value_collect.items()},
        "coordinates": coords,
        "duplicate_threshold": {
            "max_levenshtein": args.max_dist,
            "min_similarity": args.min_sim,
        },
        "duplicate_clusters": dup_clusters,
    }
    args.full_out.parent.mkdir(parents=True, exist_ok=True)
    args.full_out.write_text(
        json.dumps(full, ensure_ascii=False, indent=2, sort_keys=False),
        encoding="utf-8",
    )

    print(f"Report:   {args.report}")
    print(f"Full:     {args.full_out}")
    print(f"Encoding: {encoding}")
    print(f"PLAC:     {len(plac_sts)} (unique values: {len(unique_plac)})")
    print(f"ADDR:     {len(addr_sts)}")
    print(f"Dup clusters: {len(dup_clusters)}")
    return 0


def _find_example(sts: list[Subtree], schema: tuple[tuple[int, str], ...]) -> str | None:
    for s in sts:
        if subtree_schema(s) == schema:
            return f"{s.owner_xref or '?'} {s.owner_type or '?'}"
    return None


def _parse_geo(s: str) -> float:
    """GEDCOM-Geo: 'N52.5200' / 'E13.4050' / '52.5200' → float (Süd/West negativ)."""
    s = s.strip()
    if not s:
        raise ValueError("leer")
    sign = 1.0
    if s[0] in ("N", "n", "E", "e"):
        s = s[1:]
    elif s[0] in ("S", "s", "W", "w"):
        sign = -1.0
        s = s[1:]
    return sign * float(s)


if __name__ == "__main__":
    raise SystemExit(main())
