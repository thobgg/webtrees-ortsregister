<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Ortsregister\Dto\PlaceTask;

/**
 * Grammatik-Kern für Orts-Aufgaben im Baum (#7): PlaceTask ↔ `1 _TODO`-Struktur
 * am `_LOC`-Record. REIN (keine DB/Tree) — isoliert testbar.
 *
 * Form (webtrees-nativ, am Core verifiziert — `ResearchTask` = Text als Wert,
 * Subtags DATE/_WT_USER wie beim INDI/FAM-Aufgabenmodul; STAT aus GEDCOM-L):
 *
 *   1 _TODO <text>          (mehrzeilig via 2 CONT)
 *   2 DATE 12 JUL 2026      (aus `created` YYYY-MM-DD; leer → weggelassen)
 *   2 _WT_USER thomas       (Bearbeiter; leer → weggelassen)
 *   2 STAT completed        (nur bei erledigt)
 *   2 _UID 3f9a2b1c4d5e     (Modul-Task-ID für den Round-Trip)
 *
 * `setTasks()` ersetzt ALLE Level-1-`_TODO`-Blöcke des Records (das Modul ist
 * der einzige Schreiber von `_LOC:_TODO`); NAME/_GOV/MAP/NOTE bleiben unberührt.
 */
final class LocTodoMapper
{
    private const MONTHS = [1 => 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    // ---------------------------------------------------------------
    // Serialisieren
    // ---------------------------------------------------------------

    /** Ein Task als `1 _TODO …`-Block. */
    public function taskToFact(PlaceTask $task): string
    {
        $lines = ['1 _TODO ' . strtr(trim($task->text), ["\n" => "\n2 CONT "])];

        $date = $this->gedcomDate($task->created);
        if ($date !== '') {
            $lines[] = '2 DATE ' . $date;
        }
        if (trim($task->author) !== '') {
            $lines[] = '2 _WT_USER ' . trim($task->author);
        }
        if (!$task->isOpen()) {
            $lines[] = '2 STAT completed';
        }
        if (trim($task->id) !== '') {
            $lines[] = '2 _UID ' . trim($task->id);
        }
        return implode("\n", $lines);
    }

    /**
     * Ersetzt die `_TODO`-Blöcke eines `_LOC`-Record-GEDCOMs durch $tasks.
     * Leere Liste → alle `_TODO` entfernt. Alles andere bleibt byte-identisch.
     *
     * @param list<PlaceTask> $tasks
     */
    public function setTasks(string $recordGedcom, array $tasks): string
    {
        $lines = explode("\n", $recordGedcom);
        $n     = count($lines);
        $out   = [];

        for ($i = 0; $i < $n; $i++) {
            $line = rtrim($lines[$i], "\r");
            if ($line === '1 _TODO' || str_starts_with($line, '1 _TODO ')) {
                // Block samt Level-≥2-Kindern überspringen
                while ($i + 1 < $n && preg_match('/^([2-9]|\d\d)/', rtrim($lines[$i + 1], "\r")) === 1) {
                    $i++;
                }
                continue;
            }
            $out[] = $lines[$i];
        }

        foreach ($tasks as $task) {
            if (trim($task->text) !== '') {
                $out[] = $this->taskToFact($task);
            }
        }
        return implode("\n", $out);
    }

    // ---------------------------------------------------------------
    // Parsen
    // ---------------------------------------------------------------

    /**
     * Liest alle `1 _TODO`-Blöcke eines Record-GEDCOMs als Tasks.
     *
     * @return list<PlaceTask>
     */
    public function tasksFromRecord(string $recordGedcom): array
    {
        $lines = explode("\n", $recordGedcom);
        $n     = count($lines);
        $tasks = [];

        for ($i = 0; $i < $n; $i++) {
            $line = rtrim($lines[$i], "\r");
            if (!preg_match('/^1 _TODO(?: (.*))?$/', $line, $m)) {
                continue;
            }
            $text    = $m[1] ?? '';
            $created = '';
            $author  = '';
            $status  = PlaceTask::STATUS_OPEN;
            $id      = '';

            for ($j = $i + 1; $j < $n; $j++) {
                $sub = rtrim($lines[$j], "\r");
                if (!preg_match('/^([2-9]|\d\d)\s+(\S+)(?:\s(.*))?$/', $sub, $sm)) {
                    break;
                }
                $val = isset($sm[3]) ? trim($sm[3]) : '';
                switch ($sm[2]) {
                    case 'CONT':     $text .= "\n" . $val; break;
                    case 'CONC':     $text .= $val; break;
                    case 'DATE':     $created = $this->isoDate($val); break;
                    case '_WT_USER': $author = $val; break;
                    case 'STAT':     $status = strtolower($val) === 'completed' ? PlaceTask::STATUS_DONE : PlaceTask::STATUS_OPEN; break;
                    case '_UID':     $id = $val; break;
                }
                $i = $j;
            }

            if (trim($text) !== '') {
                $tasks[] = new PlaceTask(
                    id:      $id !== '' ? $id : substr(md5($text . '|' . $created . '|' . count($tasks)), 0, 12),
                    text:    $text,
                    status:  $status,
                    created: $created,
                    author:  $author,
                );
            }
        }
        return $tasks;
    }

    // ---------------------------------------------------------------
    // Datums-Konvertierung (rein)
    // ---------------------------------------------------------------

    /** `2026-07-12` → `12 JUL 2026`; ungültig/leer → ''. */
    public function gedcomDate(string $iso): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($iso), $m) !== 1) {
            return '';
        }
        $month = (int) $m[2];
        $day   = (int) $m[3];
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return '';
        }
        return sprintf('%d %s %s', $day, self::MONTHS[$month], $m[1]);
    }

    /** `12 JUL 2026` → `2026-07-12`; anderes/Unlesbares → ''. */
    public function isoDate(string $gedcom): string
    {
        if (preg_match('/^(\d{1,2})\s+([A-Z]{3})\s+(\d{4})$/', strtoupper(trim($gedcom)), $m) !== 1) {
            return '';
        }
        $month = array_search($m[2], self::MONTHS, true);
        if ($month === false) {
            return '';
        }
        return sprintf('%s-%02d-%02d', $m[3], $month, (int) $m[1]);
    }
}
