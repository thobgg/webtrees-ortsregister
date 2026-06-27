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
} else {
    // Fallback: PSR-4-Autoloader für das Install-ZIP (git archive ohne vendor/)
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Ortsregister\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    });
}

return new OrtsregisterModule();
