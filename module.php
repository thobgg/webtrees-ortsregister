<?php

/**
 * Ortsregister – webtrees module entry point
 *
 * Diese Datei wird von webtrees automatisch eingebunden
 * wenn das Modul in modules_v4/ortsregister/ liegt.
 * Sie muss eine Instanz der Modulklasse zurückgeben.
 */

declare(strict_types=1);

use Ortsregister\OrtsregisterModule;

// Composer-Autoloader des Moduls laden (falls kein globaler Autoloader greift)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

return new OrtsregisterModule();
