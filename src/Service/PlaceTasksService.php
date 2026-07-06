<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\PlaceTask;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use RuntimeException;

/**
 * Read/Save für strukturierte Aufgaben pro Ort.
 *
 * Storage: `media/<root>/<ortsname>/_tasks.json`
 * Format:  {"tasks":[{"id":"...","text":"...","status":"open|done"}, ...]}
 *
 * Atomare Operationen: add/toggle/updateText/delete — jede schreibt die
 * komplette Datei (LOCK_EX), da Listen typischerweise klein bleiben (<100 Tasks).
 */
class PlaceTasksService
{
    public const FILENAME = '_tasks.json';

    public function __construct(
        private readonly string $folderRoot = 'orte',
    ) {}

    /**
     * @return list<PlaceTask>
     */
    public function read(Tree $tree, string $placeName): array
    {
        $path = $this->absolutePath($tree, $placeName);
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
        if (!is_array($decoded) || !isset($decoded['tasks']) || !is_array($decoded['tasks'])) {
            return [];
        }
        $tasks = [];
        foreach ($decoded['tasks'] as $raw) {
            if (is_array($raw) && ($raw['id'] ?? '') !== '') {
                $tasks[] = PlaceTask::fromArray($raw);
            }
        }
        return $tasks;
    }

    /**
     * @param string $author  webtrees-Anzeigename des Bearbeiters (optional)
     * @param string $created Erstellungsdatum YYYY-MM-DD; leer → heute
     */
    public function add(Tree $tree, string $placeName, string $text, string $author = '', string $created = ''): PlaceTask
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Leere Aufgabe.');
        }
        $tasks = $this->read($tree, $placeName);
        $task  = new PlaceTask(
            $this->generateId(),
            $text,
            PlaceTask::STATUS_OPEN,
            $created !== '' ? $created : date('Y-m-d'),
            trim($author),
        );
        $tasks[] = $task;
        $this->writeAll($tree, $placeName, $tasks);
        return $task;
    }

    public function toggle(Tree $tree, string $placeName, string $id): ?PlaceTask
    {
        $tasks  = $this->read($tree, $placeName);
        $result = null;
        foreach ($tasks as $i => $t) {
            if ($t->id === $id) {
                $tasks[$i] = $t->toggled();
                $result    = $tasks[$i];
                break;
            }
        }
        if ($result !== null) {
            $this->writeAll($tree, $placeName, $tasks);
        }
        return $result;
    }

    public function updateText(Tree $tree, string $placeName, string $id, string $newText): ?PlaceTask
    {
        $newText = trim($newText);
        if ($newText === '') {
            throw new RuntimeException('Leerer Aufgabentext.');
        }
        $tasks  = $this->read($tree, $placeName);
        $result = null;
        foreach ($tasks as $i => $t) {
            if ($t->id === $id) {
                $tasks[$i] = $t->withText($newText);
                $result    = $tasks[$i];
                break;
            }
        }
        if ($result !== null) {
            $this->writeAll($tree, $placeName, $tasks);
        }
        return $result;
    }

    public function delete(Tree $tree, string $placeName, string $id): bool
    {
        $tasks = $this->read($tree, $placeName);
        $new   = array_values(array_filter($tasks, fn(PlaceTask $t) => $t->id !== $id));
        if (count($new) === count($tasks)) {
            return false;
        }
        $this->writeAll($tree, $placeName, $new);
        return true;
    }

    /**
     * @param list<PlaceTask> $tasks
     */
    private function writeAll(Tree $tree, string $placeName, array $tasks): void
    {
        $path = $this->absolutePath($tree, $placeName);
        if ($path === null) {
            throw new RuntimeException('Ungültiger Ortsname.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Ordner konnte nicht angelegt werden: ' . $dir);
        }

        if ($tasks === []) {
            // Leere Liste → Datei löschen, kein leerer JSON-Müll
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }

        $payload = ['tasks' => array_map(static fn(PlaceTask $t) => $t->toArray(), $tasks)];
        $json    = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Schreiben fehlgeschlagen: ' . $path);
        }
    }

    private function absolutePath(Tree $tree, string $placeName): ?string
    {
        $placeName = trim($placeName);
        if ($placeName === ''
            || str_contains($placeName, '/')
            || str_contains($placeName, '\\')
            || str_contains($placeName, '..')
        ) {
            return null;
        }
        $mediaDir = $tree->getPreference('MEDIA_DIRECTORY', 'media/');
        return Webtrees::DATA_DIR . $mediaDir . trim($this->folderRoot, '/') . '/' . $placeName . '/' . self::FILENAME;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(6)); // 12 hex chars
    }
}
