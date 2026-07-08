<?php
// Read-only: vergleicht ALT (Blattnamen-MAX) vs NEU (Vollpfad) fuer ALLE Orte und
// listet die, die durch den hierarchie-genauen Read Koordinaten VERLIEREN.
// Nimmt PDO ODER mysqli (was verfuegbar ist). Gibt bei Fehlern KEINE Zugangsdaten aus.
//
//   <php-mit-pdo_mysql-oder-mysqli> modules_v4/ortsregister/tools/loc_coord_compare.php

$root = dirname(__DIR__, 3);
$cfg  = parse_ini_file($root . '/data/config.ini.php');
if ($cfg === false) { fwrite(STDERR, "config.ini.php nicht lesbar\n"); exit(1); }
$pfx  = $cfg['tblpfx'] ?? '';
$host = $cfg['dbhost'] ?? 'localhost';
$port = (int) ($cfg['dbport'] ?? 3306);
$name = $cfg['dbname'] ?? '';
$user = $cfg['dbuser'] ?? '';
$pass = $cfg['dbpass'] ?? '';

/** @return callable(string):array<int,array<string,mixed>> */
function make_query(string $host, int $port, string $name, string $user, string $pass): callable
{
    if (in_array('mysql', PDO::getAvailableDrivers(), true)) {
        try {
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            echo "Verbindung: PDO/mysql\n";
            return fn(string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            fwrite(STDERR, "PDO-Verbindung fehlgeschlagen (Details unterdrueckt).\n");
        }
    }
    if (extension_loaded('mysqli')) {
        $m = @mysqli_connect($host, $user, $pass, $name, $port);
        if ($m instanceof mysqli) {
            echo "Verbindung: mysqli\n";
            return function (string $sql) use ($m): array {
                $res = mysqli_query($m, $sql);
                $out = [];
                if ($res instanceof mysqli_result) { while ($r = mysqli_fetch_assoc($res)) { $out[] = $r; } }
                return $out;
            };
        }
        fwrite(STDERR, "mysqli-Verbindung fehlgeschlagen.\n");
    }
    fwrite(STDERR, "Kein mysql-Treiber verfuegbar (weder PDO/mysql noch mysqli).\n");
    exit(1);
}

$q = make_query($host, $port, $name, $user, $pass);

$fullPathOf = function (array $byId, int $id): string {
    $parts = []; $cur = $id; $seen = [];
    while ($cur > 0 && isset($byId[$cur]) && !isset($seen[$cur])) { $seen[$cur] = true; $parts[] = $byId[$cur]['place']; $cur = $byId[$cur]['parent']; }
    return implode(', ', $parts);
};

// place_location (global)
$locById = [];
foreach ($q("SELECT id, parent_id, place, latitude, longitude FROM {$pfx}place_location") as $r) {
    $locById[(int) $r['id']] = ['place' => (string) $r['place'], 'parent' => $r['parent_id'] === null ? 0 : (int) $r['parent_id'],
        'lat' => $r['latitude'] === null ? null : (float) $r['latitude'], 'lon' => $r['longitude'] === null ? null : (float) $r['longitude']];
}
$locByPath = []; $locByLeaf = [];
foreach ($locById as $id => $info) {
    $locByPath[$fullPathOf($locById, $id)] = [$info['lat'], $info['lon']];
    $leaf = $info['place'];
    if (!isset($locByLeaf[$leaf])) { $locByLeaf[$leaf] = [null, null]; }
    if ($info['lat'] !== null) { $locByLeaf[$leaf][0] = max($locByLeaf[$leaf][0] ?? -INF, $info['lat']); }
    if ($info['lon'] !== null) { $locByLeaf[$leaf][1] = max($locByLeaf[$leaf][1] ?? -INF, $info['lon']); }
}

foreach ($q("SELECT DISTINCT p_file FROM {$pfx}places") as $tr) {
    $tid   = (int) $tr['p_file'];
    $pById = [];
    foreach ($q("SELECT p_id, p_place, p_parent_id FROM {$pfx}places WHERE p_file = {$tid}") as $r) {
        $pById[(int) $r['p_id']] = ['place' => (string) $r['p_place'], 'parent' => (int) $r['p_parent_id']];
    }
    $withOld = $withNew = $lost = $changed = 0; $lostList = [];
    foreach ($pById as $pid => $info) {
        $old = $locByLeaf[$info['place']] ?? [null, null];
        $new = $locByPath[$fullPathOf($pById, $pid)] ?? [null, null];
        $oldHas = $old[0] !== null && $old[1] !== null;
        $newHas = $new[0] !== null && $new[1] !== null;
        if ($oldHas) { $withOld++; }
        if ($newHas) { $withNew++; }
        if ($oldHas && !$newHas) { $lost++; if (count($lostList) < 40) { $lostList[] = sprintf('%s  (alt %.4f,%.4f)', $fullPathOf($pById, $pid), $old[0], $old[1]); } }
        if ($oldHas && $newHas && (abs($old[0] - $new[0]) > 1e-6 || abs($old[1] - $new[1]) > 1e-6)) { $changed++; }
    }
    echo "\n=== Baum {$tid} ===\n";
    echo "  Orte gesamt: " . count($pById) . "\n";
    echo "  mit Koordinaten ALT (Blatt-MAX): {$withOld}\n";
    echo "  mit Koordinaten NEU (Vollpfad):  {$withNew}\n";
    echo "  Koordinaten geaendert: {$changed}\n";
    echo "  Koordinaten VERLOREN (alt hatte, neu nicht): {$lost}\n";
    foreach ($lostList as $l) { echo "    - {$l}\n"; }
    if ($lost > count($lostList)) { echo "    … und " . ($lost - count($lostList)) . " weitere\n"; }
}

echo "\nFertig. Nichts geschrieben.\n";
