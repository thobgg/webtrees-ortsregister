<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\PlaceTask;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use RuntimeException;

/**
 * Orts-Aufgaben im Baum (#7, Daten-Doktrin): Heimat der Aufgaben ist der
 * `_LOC`-Record (`1 _TODO`-Strukturen, Grammatik in LocTodoMapper) — sie reisen
 * im GEDCOM-Export mit und überstehen Server-/DB-Umzüge.
 *
 * Gleiche öffentliche API wie der bisherige Datei-Store (read/add/toggle/
 * updateText/delete), damit Handler und Ortsseite nur den Typ wechseln.
 *
 * Migration-on-edit: Solange der `_LOC` keine `_TODO`-Blöcke trägt, liest der
 * Store die alte `_tasks.json` (Fallback). Beim ERSTEN Schreiben wandern alle
 * Aufgaben in den `_LOC`, und die JSON wird in Rente geschickt (umbenannt zu
 * `_tasks.json.migriert`) — sonst würde eine später geleerte Aufgabenliste die
 * alten JSON-Aufgaben wiederauferstehen lassen.
 */
final class PlaceTasksLocStore
{
    public function __construct(
        private readonly LocationReader     $reader,
        private readonly LocTodoMapper      $mapper,
        private readonly OperationBackup    $backup,
        private readonly PlaceTasksService  $legacy,
        private readonly PlaceFolderLocator $folderLocator,
    ) {}

    // ---------------------------------------------------------------
    // Lesen
    // ---------------------------------------------------------------

    /** @return list<PlaceTask> */
    public function read(Tree $tree, string $placeName): array
    {
        foreach ($this->reader->forPlaceName($tree, $placeName) as $id) {
            $record = Registry::locationFactory()->make($id->xref, $tree);
            if ($record === null) {
                continue;
            }
            $tasks = $this->mapper->tasksFromRecord($record->gedcom());
            if ($tasks !== []) {
                return $tasks;
            }
        }
        // Kein _LOC mit Aufgaben → Alt-Datei (bis zur Migration beim ersten Schreiben).
        return $this->legacy->read($tree, $placeName);
    }

    // ---------------------------------------------------------------
    // Mutationen (Signaturen wie der Datei-Store)
    // ---------------------------------------------------------------

    public function add(Tree $tree, string $placeName, string $text, string $author = '', string $created = ''): PlaceTask
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Aufgabentext darf nicht leer sein.');
        }
        $task = new PlaceTask(
            id:      bin2hex(random_bytes(6)),
            text:    $text,
            status:  PlaceTask::STATUS_OPEN,
            created: $created !== '' ? $created : date('Y-m-d'),
            author:  trim($author),
        );
        $tasks   = $this->read($tree, $placeName);
        $tasks[] = $task;
        $this->saveAll($tree, $placeName, $tasks);
        return $task;
    }

    public function toggle(Tree $tree, string $placeName, string $id): ?PlaceTask
    {
        return $this->mutate($tree, $placeName, $id, static fn (PlaceTask $t): ?PlaceTask => $t->toggled());
    }

    public function updateText(Tree $tree, string $placeName, string $id, string $newText): ?PlaceTask
    {
        $newText = trim($newText);
        if ($newText === '') {
            return null;
        }
        return $this->mutate($tree, $placeName, $id, static fn (PlaceTask $t): ?PlaceTask => $t->withText($newText));
    }

    public function delete(Tree $tree, string $placeName, string $id): bool
    {
        $tasks = $this->read($tree, $placeName);
        $rest  = array_values(array_filter($tasks, static fn (PlaceTask $t): bool => $t->id !== $id));
        if (count($rest) === count($tasks)) {
            return false;
        }
        $this->saveAll($tree, $placeName, $rest);
        return true;
    }

    // ---------------------------------------------------------------
    // Intern
    // ---------------------------------------------------------------

    /** @param callable(PlaceTask):?PlaceTask $fn */
    private function mutate(Tree $tree, string $placeName, string $id, callable $fn): ?PlaceTask
    {
        $tasks   = $this->read($tree, $placeName);
        $changed = null;
        foreach ($tasks as $k => $t) {
            if ($t->id === $id) {
                $changed = $fn($t);
                if ($changed === null) {
                    return null;
                }
                $tasks[$k] = $changed;
                break;
            }
        }
        if ($changed === null) {
            return null;
        }
        $this->saveAll($tree, $placeName, array_values($tasks));
        return $changed;
    }

    /**
     * Schreibt die komplette Liste in den `_LOC` (find-or-create), sichert den
     * Vor-Stand, schickt die Alt-JSON in Rente.
     *
     * @param list<PlaceTask> $tasks
     */
    private function saveAll(Tree $tree, string $placeName, array $tasks): void
    {
        $this->assertAutoAccept();

        $record = $this->resolveOrCreate($tree, $placeName);
        $pre    = $record->gedcom();
        $new    = $this->mapper->setTasks($pre, $tasks);

        if ($new !== $pre) {
            $this->backup->write('loctasks_' . $placeName, [
                'version'    => 1,
                'operation'  => 'loc_tasks',
                'place_name' => $placeName,
                'xref'       => $record->xref(),
                'pre_gedcom' => $pre,
            ]);
            $record->updateRecord($new, true);
        }

        $this->retireLegacyFile($tree, $placeName);
    }

    /**
     * Passenden `_LOC` holen; keiner da → minimalen anlegen (wie Beschreibung/W1).
     * Bei mehreren gleichnamigen gewinnt der, der schon Aufgaben trägt — Lese- und
     * Schreib-Record müssen derselbe sein, sonst entstünden Dubletten.
     */
    private function resolveOrCreate(Tree $tree, string $placeName): GedcomRecord
    {
        $first = null;
        foreach ($this->reader->forPlaceName($tree, $placeName) as $id) {
            $record = Registry::locationFactory()->make($id->xref, $tree);
            if ($record === null) {
                continue;
            }
            if ($this->mapper->tasksFromRecord($record->gedcom()) !== []) {
                return $record;
            }
            $first ??= $record;
        }
        return $first ?? $tree->createRecord("0 @@ _LOC\n1 NAME " . strtr(trim($placeName), ["\n" => "\n2 CONT "]));
    }

    /** `_tasks.json` → `_tasks.json.migriert` (einmalig; Inhalt bleibt als Alt-Kopie erhalten). */
    private function retireLegacyFile(Tree $tree, string $placeName): void
    {
        try {
            $folder = $this->folderLocator->folder($tree, $placeName);
            if ($folder === null) {
                return;
            }
            $path = rtrim($folder, '/') . '/' . PlaceTasksService::FILENAME;
            if (is_file($path)) {
                @rename($path, $path . '.migriert');
            }
        } catch (\Throwable) {
            // Rente fehlgeschlagen — unkritisch, der _LOC hat ab jetzt ohnehin Vorrang beim Lesen.
        }
    }

    private function assertAutoAccept(): void
    {
        if (Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) !== '1') {
            throw new RuntimeException(
                'Zum Speichern der Aufgaben im _LOC-Record muss in deinen Kontoeinstellungen '
                . '„Änderungen automatisch übernehmen" aktiv sein — sonst blieben die Änderungen in der Moderation hängen.'
            );
        }
    }
}
