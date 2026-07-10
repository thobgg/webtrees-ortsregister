<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Dto\OrtDto;
use Ortsregister\Service\VariantenGruppierung;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Varianten-Gruppen für die Orte-Liste (Issue #11): dieselben Blattnamen unter
 * verschieden geschriebenen Elternketten (DEU/Germany/Deutschland …) sind
 * wahrscheinlich EIN realer Ort — die Zählung muss das sichtbar machen, ohne
 * zu entscheiden (das tut der Nutzer). DB-frei.
 */
#[CoversClass(VariantenGruppierung::class)]
final class VariantenGruppierungTest extends TestCase
{
    private function ort(int $id, string $name, string $pfad, ?string $gov = null): OrtDto
    {
        return new OrtDto(id: $id, name: $name, vollstaendigerPfad: $pfad, anzahlEreignisse: 0, govId: $gov);
    }

    public function testHermannsFallSiebenGleicheBlattnamen(): void
    {
        // Ein realer Ort, 7 Schreibweisen der Elternkette → 7 places-Datensätze.
        $orte = [];
        foreach (['DEU', 'Germany', 'Deutschland', 'GERMANY', 'D', 'BRD', 'Deutschland '] as $i => $land) {
            $orte[] = $this->ort($i + 1, 'Kirchheim', "Kirchheim, $land");
        }
        $orte[] = $this->ort(99, 'Weiler', 'Weiler, Deutschland'); // Kontrolle: Einzelgänger

        $g = VariantenGruppierung::zaehle($orte);

        self::assertSame(7, $g['name']['kirchheim'] ?? 0);
        self::assertSame(1, $g['name']['weiler'] ?? 0);
        self::assertSame([], $g['gov']);
    }

    public function testNameKeyFaengtGrossKleinUndWhitespace(): void
    {
        $orte = [
            $this->ort(1, 'Oberurbach',  'Oberurbach, Amt Schorndorf'),
            $this->ort(2, 'oberurbach',  'oberurbach, OA Schorndorf'),
            $this->ort(3, ' Oberurbach', ' Oberurbach, Württemberg'),
        ];
        $g = VariantenGruppierung::zaehle($orte);

        self::assertSame(3, $g['name']['oberurbach'] ?? 0);
    }

    public function testGovGruppeZaehltNurVerknuepfte(): void
    {
        $orte = [
            $this->ort(1, 'Oberurbach', 'Oberurbach, Amt Schorndorf', 'OBEACH_W7067'),
            $this->ort(2, 'Oberurbach', 'Oberurbach, OA Schorndorf',  'OBEACH_W7067'),
            $this->ort(3, 'Oberurbach', 'Oberurbach, Rems-Murr-Kreis'),              // unverknüpft
            $this->ort(4, 'Urbach',     'Urbach, Rems-Murr-Kreis',    'URBACHJN48TT'), // andere Kennung
        ];
        $g = VariantenGruppierung::zaehle($orte);

        self::assertSame(2, $g['gov']['OBEACH_W7067'] ?? 0);
        self::assertSame(1, $g['gov']['URBACHJN48TT'] ?? 0);
        self::assertSame(3, $g['name']['oberurbach'] ?? 0); // Namens-Signal unabhängig davon
    }

    public function testLeereNamenWerdenIgnoriert(): void
    {
        $g = VariantenGruppierung::zaehle([$this->ort(1, '  ', ' '), $this->ort(2, '', '')]);
        self::assertSame([], $g['name']);
    }
}
