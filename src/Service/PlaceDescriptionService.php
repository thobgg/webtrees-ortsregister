<?php

declare(strict_types=1);

namespace Ortsregister\Service;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Tree;
use RuntimeException;

/**
 * Ortsbeschreibung (der `notes.md`-Slot) im `_LOC` NOTE — erster Zug der Daten-Doktrin:
 * erhaltenswerter Text ohne eigenes Asset gehört in den Baum, nicht in eine lose Datei.
 * Der `_LOC` NOTE ist ab hier das ORIGINAL; die `notes.md` dient nur noch als Fallback,
 * bis der Ort seine Beschreibung einmal über das Modul gespeichert hat (Migration-on-edit).
 *
 * Nutzt den reinen, getesteten `LocationWriter::setInlineNote()` für die GEDCOM-Chirurgie;
 * dieser Service macht nur DB/Tree-Arbeit: `_LOC` finden-oder-anlegen, lesen, schreiben,
 * sichern. Schreibt additiv, tastet NAME/_GOV/MAP und Pointer-Notizen nicht an.
 */
final class PlaceDescriptionService
{
    public function __construct(
        private readonly LocBindingService $binding,
        private readonly LocationReader    $reader,
        private readonly LocationWriter    $writer,
        private readonly OperationBackup   $backup,
    ) {}

    /**
     * Beschreibung aus dem GEBUNDENEN `_LOC` NOTE, oder null. REIN LESEND
     * (bis auf das Persistieren einer neu gefundenen Bindung — Cache-Charakter).
     * Bindung statt Namens-Match: gleichnamige Orte (Friedhof A/B) teilen sich
     * sonst fälschlich eine Beschreibung.
     */
    public function read(Tree $tree, int $placeId, string $leaf): ?string
    {
        $record = $this->binding->resolve($tree, $placeId, $leaf);
        if ($record === null) {
            return null;
        }
        return $this->reader->parse($record->xref(), $record->gedcom())->primaryNote();
    }

    /**
     * Speichert die Beschreibung in den `_LOC` NOTE. Legt bei Bedarf einen minimalen
     * `_LOC` an (`1 NAME`), damit die Notiz ein Zuhause hat. Sichert den Vor-Stand.
     * Leerer Text entfernt die Beschreibung.
     *
     * @return array{xref:string, backup_path:?string, written:bool}
     */
    public function save(Tree $tree, int $placeId, string $leaf, ?string $markdown): array
    {
        $this->assertAutoAccept();

        $record = $this->binding->resolveOrCreate($tree, $placeId, $leaf);
        $pre    = $record->gedcom();
        $new    = $this->writer->setInlineNote($pre, $markdown);

        if ($new === $pre) {
            return ['xref' => $record->xref(), 'backup_path' => null, 'written' => false];
        }

        $backupPath = $this->backup->write('locdesc_' . $leaf, [
            'version'    => 1,
            'operation'  => 'loc_desc',
            'place_id'   => $placeId,
            'place_name' => $leaf,
            'xref'       => $record->xref(),
            'pre_gedcom' => $pre,
        ]);
        $record->updateRecord($new, true);

        return ['xref' => $record->xref(), 'backup_path' => $backupPath, 'written' => true];
    }

    private function assertAutoAccept(): void
    {
        if (Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) !== '1') {
            throw new RuntimeException(
                'Zum Speichern der Beschreibung im _LOC-Record muss in deinen Kontoeinstellungen '
                . '„Änderungen automatisch übernehmen" aktiv sein — sonst bliebe die Änderung in der Moderation hängen.'
            );
        }
    }
}
