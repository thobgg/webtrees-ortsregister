<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Service\DdbClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Die DDB-API 2.0 liefert Solr-Dokumente, bei denen fast jedes Feld ein Array
 * ist — die alte API 1.0 lieferte Strings. Genau daran hängt die Feld-Abbildung
 * in DdbClient, deshalb ist das hier festgenagelt (Issue #18).
 */
final class DdbClientTest extends TestCase
{
    private function firstString(mixed $value): string
    {
        $m = new ReflectionMethod(DdbClient::class, 'firstString');
        return $m->invoke(null, $value);
    }

    public function testNimmtDenErstenWertEinesSolrArrays(): void
    {
        self::assertSame('Fischbach', $this->firstString(['Fischbach', 'Zweitwert']));
    }

    public function testAkzeptiertAuchEinenBlankenString(): void
    {
        self::assertSame('Fischbach', $this->firstString('Fischbach'));
    }

    public function testTrimmtRandweisseRaum(): void
    {
        self::assertSame('Fischbach', $this->firstString(['  Fischbach  ']));
    }

    public function testLiefertLeerstringFuerNullLeeresArrayUndObjekte(): void
    {
        self::assertSame('', $this->firstString(null));
        self::assertSame('', $this->firstString([]));
        self::assertSame('', $this->firstString(new \stdClass()));
        self::assertSame('', $this->firstString([['verschachtelt']]));
    }

    public function testWandeltZahlenInStringsUm(): void
    {
        self::assertSame('1690', $this->firstString([1690]));
    }

    public function testLeererOrtsnameMachtKeinenNetzwerkCall(): void
    {
        $client = new DdbClient(new ApcuCacheService(60), 60);

        self::assertTrue($client->lookup('   ')->isEmpty());
    }
}
