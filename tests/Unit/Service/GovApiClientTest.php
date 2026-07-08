<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Dto\GovObject;
use Ortsregister\Service\GovApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(GovApiClient::class)]
final class GovApiClientTest extends TestCase
{
    /**
     * Echte getObject-Struktur (verifiziert an GOV OBEACH_W7067 = Oberurbach):
     * partOf trägt die Zeitspanne als beginYear/endYear — genau die Feldnamen, die
     * der Parser vorher NICHT gelesen hat.
     */
    public function testParsesPartOfBeginEndYearFromRealApiShape(): void
    {
        $json = [
            'id'       => 'OBEACH_W7067',
            'position' => ['lon' => 9.58, 'lat' => 48.8, 'type' => 'p'],
            'name'     => [['lang' => 'deu', 'value' => 'Oberurbach']],
            'partOf'   => [
                ['ref' => 'object_306665', 'beginYear' => 1938, 'endYear' => 1970],
                ['ref' => 'URBACHJN48TT',  'beginYear' => 1970],               // offen → heute
                ['ref' => 'object_190778', 'endYear' => 1938],
            ],
        ];

        $obj = $this->parse('OBEACH_W7067', $json);

        self::assertSame(['object_306665', 'URBACHJN48TT', 'object_190778'], $obj->partOfIds);

        self::assertSame('1938', $obj->partOfMeta['object_306665']['begin']);
        self::assertSame('1970', $obj->partOfMeta['object_306665']['end']);

        self::assertSame('1970', $obj->partOfMeta['URBACHJN48TT']['begin']);
        self::assertNull($obj->partOfMeta['URBACHJN48TT']['end']);            // läuft bis heute

        self::assertNull($obj->partOfMeta['object_190778']['begin']);
        self::assertSame('1938', $obj->partOfMeta['object_190778']['end']);

        self::assertEqualsWithDelta(48.8, (float) $obj->latitude, 1e-9);
        self::assertEqualsWithDelta(9.58, (float) $obj->longitude, 1e-9);
        self::assertSame('Oberurbach', $obj->namesByLang['deu'] ?? null);
    }

    private function parse(string $govId, array $json): GovObject
    {
        $client = (new ReflectionClass(GovApiClient::class))->newInstanceWithoutConstructor();
        // Private-Methoden sind seit PHP 8.1 ohne setAccessible() aufrufbar (Modul >= 8.2).
        $m = new ReflectionMethod(GovApiClient::class, 'parseObject');
        /** @var GovObject */
        return $m->invoke($client, $govId, $json);
    }
}
