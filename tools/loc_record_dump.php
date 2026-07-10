<?php
declare(strict_types=1);

// Diagnose: rohes GEDCOM eines _LOC-Records (oder aller _LOC mit _TODO) dumpen.
// Pure PDO, kein Autoload — läuft mit jedem PHP, das pdo_mysql hat (NAS: /usr/local/bin/php82).
//   php82 tools/loc_record_dump.php X2909        → ein Record
//   php82 tools/loc_record_dump.php --todos      → alle _LOC, die _TODO enthalten
// Gibt NIE Zugangsdaten aus.

$config = parse_ini_file(__DIR__ . '/../../../data/config.ini.php');
if ($config === false || !isset($config['dbname'])) {
    fwrite(STDERR, "config.ini.php nicht lesbar\n");
    exit(1);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['dbhost'], $config['dbport'], $config['dbname']),
    $config['dbuser'],
    $config['dbpass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$prefix = $config['tblpfx'] ?? 'wt_';

$arg = $argv[1] ?? '--todos';
if ($arg === '--todos') {
    $rows = $pdo->query("SELECT o_id, o_file, o_gedcom FROM {$prefix}other WHERE o_type='_LOC' AND o_gedcom LIKE '%_TODO%'")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        echo "kein _LOC mit _TODO gefunden\n";
        exit(0);
    }
    foreach ($rows as $r) {
        echo "===== @{$r['o_id']}@ (tree {$r['o_file']}) =====\n{$r['o_gedcom']}\n\n";
    }
} else {
    $stmt = $pdo->prepare("SELECT o_id, o_file, o_gedcom FROM {$prefix}other WHERE o_id = ? AND o_type='_LOC'");
    $stmt->execute([$arg]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $r === false ? "@{$arg}@ nicht gefunden\n" : "===== @{$r['o_id']}@ (tree {$r['o_file']}) =====\n{$r['o_gedcom']}\n";
}
