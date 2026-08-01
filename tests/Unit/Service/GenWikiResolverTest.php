<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Cache\ApcuCacheService;
use Ortsregister\Service\GenWikiResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Die Antwort-Formen stammen aus echten API-Aufrufen (2026-08-01),
 * siehe Klassen-Doc von GenWikiResolver.
 */
#[CoversClass(GenWikiResolver::class)]
final class GenWikiResolverTest extends TestCase
{
    public function testResolvesRedirectToArticle(): void
    {
        $r = $this->make()->parse([
            'batchcomplete' => '',
            'query' => [
                'redirects' => [['from' => 'GOV:KOLOLNJO30LW', 'to' => 'Köln']],
                'pages'     => ['5766' => [
                    'pageid'  => 5766,
                    'ns'      => 0,
                    'title'   => 'Köln',
                    'fullurl' => 'https://wiki.genealogy.net/K%C3%B6ln',
                ]],
            ],
        ]);
        self::assertTrue($r['found']);
        self::assertSame('Köln', $r['title']);
        self::assertSame('https://wiki.genealogy.net/K%C3%B6ln', $r['url']);
    }

    public function testUrlComesFromApiNotFromOwnEncoding(): void
    {
        // MediaWiki lässt Klammern und Schrägstriche stehen — wir übernehmen die
        // gelieferte Adresse unverändert, statt die Regeln nachzubauen.
        $r = $this->make()->parse($this->response('GOV:URBACHJN48TT', 'Urbach (Rems)', 'https://wiki.genealogy.net/Urbach_(Rems)'));
        self::assertSame('https://wiki.genealogy.net/Urbach_(Rems)', $r['url']);

        $r2 = $this->make()->parse($this->response('GOV:X', 'Kreis Ahaus/Ortschaften', 'https://wiki.genealogy.net/Kreis_Ahaus/Ortschaften'));
        self::assertSame('https://wiki.genealogy.net/Kreis_Ahaus/Ortschaften', $r2['url']);
    }

    public function testMissingPageIsNotFound(): void
    {
        $r = $this->make()->parse([
            'batchcomplete' => '',
            'query' => [
                'pages' => ['-1' => ['ns' => 114, 'title' => 'GOV:KOLOLNJO30LW2', 'missing' => '']],
            ],
        ]);
        self::assertFalse($r['found']);
    }

    public function testPageWithoutRedirectIsNotFound(): void
    {
        // GOV:-Seite existiert, zeigt aber auf nichts — kein Link.
        $r = $this->make()->parse([
            'query' => ['pages' => ['42' => [
                'pageid'  => 42,
                'title'   => 'GOV:XYZ',
                'fullurl' => 'https://wiki.genealogy.net/GOV:XYZ',
            ]]],
        ]);
        self::assertFalse($r['found']);
    }

    public function testRedirectWithoutUsableUrlIsNotFound(): void
    {
        // Redirect da, aber keine URL geliefert → kein geratener Link.
        $r = $this->make()->parse([
            'query' => [
                'redirects' => [['from' => 'GOV:X', 'to' => 'Köln']],
                'pages'     => ['1' => ['pageid' => 1, 'title' => 'Köln']],
            ],
        ]);
        self::assertFalse($r['found']);

        // URL zeigt woanders hin als ins GenWiki → verworfen.
        $r2 = $this->make()->parse($this->response('GOV:X', 'Köln', 'https://example.org/Koeln'));
        self::assertFalse($r2['found']);
    }

    public function testGarbageResponsesAreNotFound(): void
    {
        self::assertFalse($this->make()->parse([])['found']);
        self::assertFalse($this->make()->parse(['query' => 'kaputt'])['found']);
        self::assertFalse($this->make()->parse(['query' => ['redirects' => [['from' => 'GOV:X']]]])['found']);
        self::assertFalse($this->make()->parse(['query' => ['redirects' => [['to' => '  ']]]])['found']);
    }

    public function testInvalidIdSkipsLookupEntirely(): void
    {
        // Ungültige IDs dürfen gar nicht erst zu einem HTTP-Request führen.
        self::assertNull($this->make()->resolve(''));
        self::assertNull($this->make()->resolve('zu kurz + Leerzeichen'));
        self::assertNull($this->make()->resolve('BÖSE;DROP'));
    }

    /** @return array<string, mixed> */
    private function response(string $from, string $to, string $url): array
    {
        return [
            'query' => [
                'redirects' => [['from' => $from, 'to' => $to]],
                'pages'     => ['1' => ['pageid' => 1, 'title' => $to, 'fullurl' => $url]],
            ],
        ];
    }

    private function make(): GenWikiResolver
    {
        return new GenWikiResolver(new ApcuCacheService(60), 60);
    }
}
