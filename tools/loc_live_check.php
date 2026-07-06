<?php

declare(strict_types=1);

/**
 * Rein LESENDER Laufzeit-Check des _LOC-Readers gegen die ECHTE webtrees-DB.
 *
 * Auf einem Host mit pdo_mysql + Zugriff auf die MySQL-DB ausführen (NAS-Host),
 * NICHT im DB-losen Sandbox. Schreibt nichts, mutiert nichts.
 *
 *   php modules_v4/ortsregister/tools/loc_live_check.php
 *
 * Zeigt: alle _LOC-Records je Baum, was der Reader daraus extrahiert, und einen
 * forPlaceName()-Beispiellauf auf dem ersten gefundenen _LOC-Namen.
 */

$root = dirname(__DIR__, 3); // .../webtrees
require $root . '/vendor/autoload.php';
require $root . '/modules_v4/ortsregister/vendor/autoload.php';

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;
use Ortsregister\Service\LocationReader;

$cfg = parse_ini_file($root . '/data/config.ini.php');
if ($cfg === false) {
    fwrite(STDERR, "config.ini.php nicht lesbar\n");
    exit(1);
}

DB::connect(
    $cfg['dbtype'] ?? 'mysql',
    $cfg['dbhost'] ?? 'localhost',
    (string) ($cfg['dbport'] ?? '3306'),
    $cfg['dbname'] ?? '',
    $cfg['dbuser'] ?? '',
    $cfg['dbpass'] ?? '',
    $cfg['tblpfx'] ?? '',
    '', '', '', false,
);
echo "DB verbunden ({$cfg['dbtype']}@{$cfg['dbhost']}, prefix '{$cfg['tblpfx']}').\n";

$reader = new LocationReader();

// Tree-Double: forPlaceName/make brauchen nur ->id().
$treeDouble = static function (int $id): Tree {
    return new class($id) extends Tree {
        public function __construct(private int $tid) {}
        public function id(): int { return $this->tid; }
    };
};

// Alle Bäume mit _LOC-Records.
$treeIds = DB::table('other')->where('o_type', '=', '_LOC')->distinct()->pluck('o_file');

if ($treeIds->isEmpty()) {
    echo "Keine _LOC-Records in der Datenbank. (Reader-Pfad ist damit trivial leer — erwartet, wenn niemand _LOC nutzt.)\n";
    exit(0);
}

$exampleName = null;
foreach ($treeIds as $tid) {
    $tid  = (int) $tid;
    $tree = $treeDouble($tid);
    $rows = DB::table('other')->where('o_file', '=', $tid)->where('o_type', '=', '_LOC')->select(['o_id', 'o_gedcom'])->get();
    echo "\n=== Baum {$tid}: {$rows->count()} _LOC-Record(s) ===\n";
    foreach ($rows as $row) {
        $id = $reader->parse((string) $row->o_id, (string) $row->o_gedcom);
        $bits = [];
        $bits[] = 'Name=' . ($id->primaryName() !== '' ? $id->primaryName() : '(leer)');
        if (count($id->names) > 1) { $bits[] = 'Varianten=' . (count($id->names) - 1); }
        if ($id->hasGov()) { $bits[] = 'GOV=' . $id->govId; }
        if ($id->hasCoordinates()) { $bits[] = sprintf('Koord=%.4f,%.4f', $id->latitude, $id->longitude); }
        if ($id->type !== null) { $bits[] = 'TYPE=' . $id->type; }
        if ($id->parentXrefs !== []) { $bits[] = 'Hierarchie=' . count($id->parentXrefs); }
        echo "  @{$id->xref}@  " . implode('  ', $bits) . "\n";
        $exampleName ??= $id->primaryName() !== '' ? [$tid, $id->primaryName()] : null;
    }
}

// forPlaceName() end-to-end auf einem echten Namen.
if ($exampleName !== null) {
    [$tid, $name] = $exampleName;
    $hits = $reader->forPlaceName($treeDouble($tid), $name);
    echo "\nforPlaceName(Baum {$tid}, \"{$name}\") -> " . count($hits) . " Treffer (end-to-end gegen echte DB).\n";
}

// ---------------------------------------------------------------------------
// Test-Kandidaten für den Writer (W1): Orte OHNE _LOC finden.
// ---------------------------------------------------------------------------
$fold = static fn(string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $s)));

foreach ($treeIds as $tid) {
    $tid = (int) $tid;

    // Gefaltete Namen, die schon ein _LOC haben.
    $locNames = [];
    foreach (DB::table('other')->where('o_file', '=', $tid)->where('o_type', '=', '_LOC')->pluck('o_gedcom') as $g) {
        foreach ($reader->parse('x', (string) $g)->names as $n) {
            $locNames[$fold($n)] = true;
        }
    }

    // place_id -> Ortsname (Blatt) für diesen Baum.
    $idToName = [];
    foreach (DB::table('places')->where('p_file', '=', $tid)->select(['p_id', 'p_place'])->get() as $p) {
        $idToName[(int) $p->p_id] = (string) $p->p_place;
    }

    // (A) SOFORT anlegbar: Modul-GOV gesetzt, aber kein _LOC → CREATE ohne Vorarbeit.
    echo "\n=== Baum {$tid}: CREATE-Kandidaten (Modul-GOV gesetzt, kein _LOC) ===\n";
    $anyA = false;
    $metaRows = DB::table('ortsregister_place_meta')
        ->where('tree_id', '=', $tid)
        ->whereNotNull('gov_id')
        ->where('gov_id', '!=', '')
        ->select(['place_id', 'gov_id'])
        ->get();
    foreach ($metaRows as $m) {
        $name = $idToName[(int) $m->place_id] ?? null;
        if ($name === null || isset($locNames[$fold($name)])) {
            continue;
        }
        echo "  place_id={$m->place_id}  {$name}  (GOV {$m->gov_id})\n";
        $anyA = true;
    }
    if (!$anyA) {
        echo "  (keine — im Modul ist kaum/kein Ort mit GOV verknüpft)\n";
    }

    // (B) Orte OHNE _LOC (zum „mit GOV verknüpfen" + anlegen).
    $without = [];
    foreach ($idToName as $pid => $name) {
        if (!isset($locNames[$fold($name)])) {
            $without[] = "{$name} (place_id={$pid})";
        }
    }
    sort($without);
    echo "\n=== Baum {$tid}: Orte OHNE _LOC (" . count($without) . " gesamt) — erste 25 ===\n";
    foreach (array_slice($without, 0, 25) as $w) {
        echo "  {$w}\n";
    }
}

echo "\nFertig. Nichts geschrieben.\n";
