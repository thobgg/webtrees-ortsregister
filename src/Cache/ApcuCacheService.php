<?php

declare(strict_types=1);

namespace Ortsregister\Cache;

/**
 * APCu-basierter Cache mit Array-Fallback.
 *
 * TTL wird einmalig im Konstruktor gesetzt und von allen
 * remember()-Aufrufen ohne explizites TTL-Argument verwendet.
 * Konfigurierbar über die Modul-Einstellungsseite.
 */
class ApcuCacheService
{
    private const PREFIX = 'ortsregister:';

    private readonly bool $apcuAvailable;

    /** @var array<string, mixed> */
    private array $localCache = [];

    public function __construct(
        private readonly int $defaultTtl = 900
    ) {
        $this->apcuAvailable =
            \function_exists('apcu_fetch')
            && filter_var(\ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)
            && \PHP_SAPI !== 'cli';
    }

    // ---------------------------------------------------------------
    // Öffentliche API
    // ---------------------------------------------------------------

    /**
     * Liefert einen gecachten Wert oder ruft $callback auf.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $fullKey = self::PREFIX . $key;
        $cached  = $this->fetch($fullKey);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->store($fullKey, $value, $ttl ?? $this->defaultTtl);

        return $value;
    }

    public function forget(string $key): void
    {
        $fullKey = self::PREFIX . $key;

        if ($this->apcuAvailable) {
            \apcu_delete($fullKey);
        }

        unset($this->localCache[$fullKey]);
    }

    /**
     * Löscht alle Einträge des Moduls aus dem APCu-Cache.
     * Wird nach GEDCOM-Import und manuell per Admin-Seite aufgerufen.
     */
    public function flush(): void
    {
        if ($this->apcuAvailable) {
            $iterator = new \APCuIterator(
                '/^' . \preg_quote(self::PREFIX, '/') . '/',
                \APC_ITER_KEY
            );
            foreach ($iterator as $item) {
                \apcu_delete($item['key']);
            }
        }

        $this->localCache = [];
    }

    public function isApcuAvailable(): bool
    {
        return $this->apcuAvailable;
    }

    /** Gibt den konfigurierten Standard-TTL zurück (für Admin-Anzeige). */
    public function defaultTtl(): int
    {
        return $this->defaultTtl;
    }

    // ---------------------------------------------------------------
    // Intern
    // ---------------------------------------------------------------

    private function fetch(string $fullKey): mixed
    {
        if ($this->apcuAvailable) {
            $success = false;
            $value   = \apcu_fetch($fullKey, $success);

            return $success ? $value : null;
        }

        return $this->localCache[$fullKey] ?? null;
    }

    private function store(string $fullKey, mixed $value, int $ttl): void
    {
        if ($this->apcuAvailable) {
            \apcu_store($fullKey, $value, $ttl);
        } else {
            $this->localCache[$fullKey] = $value;
        }
    }
}
