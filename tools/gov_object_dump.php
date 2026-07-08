<?php
// Zeigt die ROHE GOV-getObject-Antwort fuer eine Kennung — beantwortet: liefert die
// API ueberhaupt part-of/located-in MIT Zeitspannen? Nur Netzwerk, keine DB/kein Autoload.
//
//   php modules_v4/ortsregister/tools/gov_object_dump.php [ITEMID]
//   (Default ITEMID = OBEACH_W7067 = Oberurbach)

$id  = $argv[1] ?? 'OBEACH_W7067';
$url = 'https://gov.genealogy.net/api/getObject?itemId=' . rawurlencode($id);
$ctx = stream_context_create(['http' => [
    'header'  => "User-Agent: webtrees-ortsregister/0.1 (+https://github.com/thobgg/webtrees-ortsregister)\r\nAccept: application/json\r\n",
    'timeout' => 20,
]]);

echo "GET {$url}\n\n";
$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) { fwrite(STDERR, "Abruf fehlgeschlagen (Netzwerk/Block).\n"); exit(1); }

$j = json_decode($raw, true);
if (!is_array($j)) {
    fwrite(STDERR, "Keine JSON-Antwort (evtl. Bot-Block). Erste 200 Zeichen:\n" . substr(trim($raw), 0, 200) . "\n");
    exit(1);
}

$pp = static fn($v): string => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

echo "=== Top-Level-Schluessel ===\n  " . implode(', ', array_keys($j)) . "\n\n";

echo "=== position (Koordinaten) ===\n" . $pp($j['position'] ?? '(fehlt)') . "\n\n";

$partOf = $j['part-of'] ?? $j['partOf'] ?? '(fehlt)';
echo "=== part-of (historische Eltern — HIER auf timeBegin/timeEnd achten) ===\n" . $pp($partOf) . "\n\n";

$locatedIn = $j['located-in'] ?? $j['locatedIn'] ?? '(fehlt)';
echo "=== located-in (aktuelle Zugehoerigkeit) ===\n" . $pp($locatedIn) . "\n\n";

// Namen (oft mit Sprache/Zeit)
echo "=== name ===\n" . $pp($j['name'] ?? '(fehlt)') . "\n";
