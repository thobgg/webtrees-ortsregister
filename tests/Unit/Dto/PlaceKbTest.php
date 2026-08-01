<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Dto;

use Ortsregister\Dto\PlaceKb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaceKb::class)]
final class PlaceKbTest extends TestCase
{
    public function testReadsLegacyArchionUrlKey(): void
    {
        $kb = PlaceKb::fromArray([
            'id'          => 'a1',
            'title'       => 'Taufen',
            'archion_url' => 'https://www.archion.de/p/abc/',
        ]);
        self::assertSame('https://www.archion.de/p/abc/', $kb->url);
    }

    public function testNewKeyWinsOverLegacyKey(): void
    {
        $kb = PlaceKb::fromArray([
            'id'          => 'a1',
            'title'       => 'Taufen',
            'url'         => 'https://data.matricula-online.eu/de/xyz/',
            'archion_url' => 'https://www.archion.de/p/abc/',
        ]);
        self::assertSame('https://data.matricula-online.eu/de/xyz/', $kb->url);
    }

    public function testWritesNewKeyOnly(): void
    {
        $kb = new PlaceKb(id: 'a1', title: 'Taufen', url: 'https://example.org/x');
        $arr = $kb->toArray();
        self::assertSame('https://example.org/x', $arr['url']);
        self::assertArrayNotHasKey('archion_url', $arr);
    }

    public function testLinkLabelForKnownProviders(): void
    {
        self::assertSame('Archion', $this->label('https://www.archion.de/p/abc/'));
        self::assertSame('Matricula', $this->label('https://data.matricula-online.eu/de/deutschland/'));
        self::assertSame('FamilySearch', $this->label('https://www.familysearch.org/ark:/1/2'));
    }

    public function testLinkLabelFallsBackToHost(): void
    {
        self::assertSame('kirchenbuch.example.org', $this->label('https://kirchenbuch.example.org/scan/1'));
        self::assertSame('example.org', $this->label('https://www.example.org/scan/1'));
    }

    public function testLinkLabelWithoutUrlOrHost(): void
    {
        self::assertSame('', (new PlaceKb(id: 'a1', title: 'T'))->linkLabel());
        self::assertSame('Digitalisat', $this->label('/lokaler/pfad.pdf'));
    }

    private function label(string $url): string
    {
        return (new PlaceKb(id: 'a1', title: 'T', url: $url))->linkLabel();
    }
}
