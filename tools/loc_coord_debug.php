<?php
// Read-only Grundwahrheit fuer #2. REINES PDO + curl, KEIN webtrees-Autoload
// -> laeuft auf jedem PHP mit pdo_mysql (egal ob 8.1/8.2/8.3). Schreibt nichts.
//
//   <php-mit-pdo_mysql> modules_v4/ortsregister/tools/loc_coord_debug.php

$root = dirname(__DIR__, 3);
$cfg  = parse_ini_file($root . '/data/config.ini.php');
if ($cfg === false) { fwrite(STDERR, "config.ini.php nicht lesbar\n"); exit(1); }

$pfx = $cfg['tblpfx'] ?? '';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $cfg['dbhost'] ?? 'localhost', $cfg['dbport'] ?? '3306', $cfg['dbname'] ?? '');
try {
    $pdo = new PDO($dsn, $cfg['dbuser'] ?? '', $cfg['dbpass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Verbindung fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}
echo "DB verbunden ({$cfg['dbhost']}, prefix '{$pfx}').\n";

// 1) place_location fuer die Blattnamen
foreach (['Pleidelsheim', 'Oberurbach'] as $name) {
    echo "\n=== {$pfx}place_location WHERE place = '{$name}' ===\n";
    $st = $pdo->prepare("SELECT id, parent_id, place, latitude, longitude FROM {$pfx}place_location WHERE place = ?");
    $st->execute([$name]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo "  (keine Zeile)\n"; }
    foreach ($rows as $r) {
        printf("  id=%s parent=%s place=%s lat=%s lon=%s\n",
            $r['id'], $r['parent_id'], $r['place'],
            $r['latitude'] ?? 'NULL', $r['longitude'] ?? 'NULL');
    }
}

// 2) Was liefert GOV wirklich? (position.lat/lon) -- via curl mit Modul-User-Agent
function govPosition(string $govId): string {
    $url = 'https://gov.genealogy.net/api/getObject?itemId=' . rawurlencode($govId);
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: webtrees-ortsregister/0.1 (+https://github.com/thobgg/webtrees-ortsregister)\r\nAccept: application/json\r\n",
        'timeout' => 15,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) { return "  Abruf fehlgeschlagen (Netzwerk/Block)\n"; }
    $j = json_decode($raw, true);
    if (!is_array($j)) { return "  keine JSON-Antwort (evtl. Bot-Block): " . substr(trim($raw), 0, 80) . "\n"; }
    $pos = $j['position'] ?? null;
    if (is_array($pos) && isset($pos['lat'], $pos['lon'])) {
        return sprintf("  hasCoordinates=JA  lat=%s  lon=%s\n", $pos['lat'], $pos['lon']);
    }
    return "  hasCoordinates=NEIN (kein position-Feld)\n";
}

foreach (['PLEEIMJN48OX' => 'Pleidelsheim', 'OBEACH_W7067' => 'Oberurbach'] as $govId => $label) {
    echo "\n=== GOV {$govId} ({$label}) ===\n" . govPosition($govId);
}

echo "\nFertig. Nichts geschrieben.\n";
