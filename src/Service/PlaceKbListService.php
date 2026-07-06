<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\PlaceKb;
use Fisharebest\Webtrees\Tree;
use RuntimeException;

/**
 * CRUD für die KB-Liste pro Ort.
 *
 *   Metadaten: `media/<root>/<ortsname>/_kb_list.json`
 *     {"schema_version":1, "kbs":[{...},...]}
 *
 *   Logbuch pro KB: `media/<root>/<ortsname>/_kb_<id>.md`
 *     (separat damit auf NAS direkt mit Texteditor editierbar)
 *
 * Atomare Operationen: add/update/delete schreiben jeweils die komplette
 * Liste neu (Listen bleiben klein, max. dutzende KBs pro Ort).
 */
class PlaceKbListService
{
    public const FILENAME       = '_kb_list.json';
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly PlaceFolderLocator $folderLocator = new PlaceFolderLocator(),
    ) {}

    /**
     * @return list<PlaceKb>
     */
    public function read(Tree $tree, string $placeName): array
    {
        $path = $this->listPath($tree, $placeName);
        if ($path === null || !is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded) || !isset($decoded['kbs']) || !is_array($decoded['kbs'])) {
            return [];
        }
        $out = [];
        foreach ($decoded['kbs'] as $row) {
            if (is_array($row) && ($row['id'] ?? '') !== '') {
                $out[] = PlaceKb::fromArray($row);
            }
        }
        return $out;
    }

    public function add(
        Tree $tree,
        string $placeName,
        string $title,
        string $type,
        ?int $yearFrom,
        ?int $yearTo,
        ?string $archionUrl,
        ?string $sourXref,
    ): PlaceKb {
        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('Titel erforderlich.');
        }
        $kbs = $this->read($tree, $placeName);
        $kb  = new PlaceKb(
            id:         $this->generateId(),
            title:      $title,
            type:       $type,
            yearFrom:   $yearFrom,
            yearTo:     $yearTo,
            archionUrl: $archionUrl,
            sourXref:   $sourXref,
        );
        $kbs[] = $kb;
        $this->writeAll($tree, $placeName, $kbs);
        return $kb;
    }

    public function update(
        Tree $tree,
        string $placeName,
        string $id,
        string $title,
        string $type,
        ?int $yearFrom,
        ?int $yearTo,
        ?string $archionUrl,
        ?string $sourXref,
    ): ?PlaceKb {
        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('Titel erforderlich.');
        }
        $kbs = $this->read($tree, $placeName);
        $result = null;
        foreach ($kbs as $i => $existing) {
            if ($existing->id === $id) {
                $kbs[$i] = new PlaceKb(
                    id:         $id,
                    title:      $title,
                    type:       $type,
                    yearFrom:   $yearFrom,
                    yearTo:     $yearTo,
                    archionUrl: $archionUrl,
                    sourXref:   $sourXref,
                );
                $result = $kbs[$i];
                break;
            }
        }
        if ($result !== null) {
            $this->writeAll($tree, $placeName, $kbs);
        }
        return $result;
    }

    public function delete(Tree $tree, string $placeName, string $id): bool
    {
        $kbs = $this->read($tree, $placeName);
        $new = array_values(array_filter($kbs, fn(PlaceKb $k) => $k->id !== $id));
        if (count($new) === count($kbs)) {
            return false;
        }
        $this->writeAll($tree, $placeName, $new);
        // Logbuch-Datei mit weg
        $logPath = $this->logbookPath($tree, $placeName, $id);
        if ($logPath !== null && is_file($logPath)) {
            @unlink($logPath);
        }
        return true;
    }

    public function readLogbook(Tree $tree, string $placeName, string $id): string
    {
        $path = $this->logbookPath($tree, $placeName, $id);
        if ($path === null || !is_file($path)) {
            return '';
        }
        return (string) @file_get_contents($path);
    }

    public function saveLogbook(Tree $tree, string $placeName, string $id, string $markdown): void
    {
        $path = $this->logbookPath($tree, $placeName, $id);
        if ($path === null) {
            throw new RuntimeException('Ungültiger Ortsname oder KB-ID.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Ordner konnte nicht angelegt werden.');
        }
        if (trim($markdown) === '') {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        if (@file_put_contents($path, $markdown, LOCK_EX) === false) {
            throw new RuntimeException('Schreiben fehlgeschlagen.');
        }
    }

    /**
     * @param list<PlaceKb> $kbs
     */
    private function writeAll(Tree $tree, string $placeName, array $kbs): void
    {
        $path = $this->listPath($tree, $placeName);
        if ($path === null) {
            throw new RuntimeException('Ungültiger Ortsname.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Ordner konnte nicht angelegt werden.');
        }
        if ($kbs === []) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'kbs'            => array_map(static fn(PlaceKb $k) => $k->toArray(), $kbs),
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Schreiben fehlgeschlagen.');
        }
    }

    private function listPath(Tree $tree, string $placeName): ?string
    {
        $dir = $this->placeFolder($tree, $placeName);
        return $dir === null ? null : $dir . '/' . self::FILENAME;
    }

    private function logbookPath(Tree $tree, string $placeName, string $id): ?string
    {
        $dir = $this->placeFolder($tree, $placeName);
        if ($dir === null) return null;
        // ID-Validierung: nur unser eigenes Format (hex)
        if (preg_match('/^[a-f0-9]{8,32}$/', $id) !== 1) {
            return null;
        }
        return $dir . '/_kb_' . $id . '.md';
    }

    private function placeFolder(Tree $tree, string $placeName): ?string
    {
        return $this->folderLocator->folder($tree, $placeName);
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(6)); // 12 hex
    }
}
