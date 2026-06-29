<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\ArchionParishLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArchionParishLookup::class)]
final class ArchionParishLookupTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/archion-test-' . uniqid('', true) . '.json';
        file_put_contents($this->fixture, json_encode([
            'archives'  => ['Archiv-Bayern', 'Archiv-Wuerttemberg'],
            'districts' => ['Dekanat-Brackenheim', 'Dekanat-Ansbach'],
            'parishes'  => [
                ['n' => 'Brackenheim',  'ai' => 1, 'di' => 0, 'p' => '/de/alle-archive/bw/.../brackenheim',  'g' => [9.0671, 49.0707]],
                ['n' => 'Bönnigheim',   'ai' => 1, 'di' => 0, 'p' => '/de/alle-archive/bw/.../boennigheim', 'g' => [9.0936, 49.0394]],
                ['n' => 'Ansbach',      'ai' => 0, 'di' => 1, 'p' => '/de/alle-archive/bay/.../ansbach',    'g' => [10.5717, 49.3017]],
                ['n' => 'Ohne Koord',   'ai' => 0, 'di' => 1, 'p' => '/de/alle-archive/x/y',                'g' => [null, null]],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->fixture);
    }

    public function testNearestWithinReturnsClosestParish(): void
    {
        $lookup = new ArchionParishLookup($this->fixture);
        // Haberschlacht ungefähr: 49.060, 9.043 — sollte Brackenheim (49.0707, 9.0671) finden
        $p = $lookup->nearestWithin(49.060, 9.043, 10.0);
        self::assertNotNull($p);
        self::assertSame('Brackenheim', $p->name);
    }

    public function testNearestWithinReturnsNullWhenAllTooFar(): void
    {
        $lookup = new ArchionParishLookup($this->fixture);
        // Berlin-Koord: alles in BW/BY > 300km weg
        self::assertNull($lookup->nearestWithin(52.52, 13.40, 50.0));
    }

    public function testNearestWithinIgnoresParishWithoutCoords(): void
    {
        $lookup = new ArchionParishLookup($this->fixture);
        $p = $lookup->nearestWithin(49.060, 9.043, 200.0);
        // „Ohne Koord" hat g:[null,null] — darf nicht gewählt werden
        self::assertNotSame('Ohne Koord', $p?->name);
    }

    public function testAllWithinReturnsRankedByDistance(): void
    {
        $lookup = new ArchionParishLookup($this->fixture);
        // Brackenheim selbst — Brackenheim 0 km, Bönnigheim ca. 4-5 km
        $hits = $lookup->allWithin(49.0707, 9.0671, 20.0);
        self::assertGreaterThanOrEqual(2, count($hits));
        self::assertSame('Brackenheim', $hits[0]['parish']->name);
        self::assertLessThan(1.0, $hits[0]['distance_km']);
        self::assertSame('Bönnigheim',  $hits[1]['parish']->name);
    }

    public function testFullUrlBuildsFromPath(): void
    {
        $lookup = new ArchionParishLookup($this->fixture);
        $p = $lookup->nearestWithin(49.0707, 9.0671, 5.0);
        self::assertNotNull($p);
        self::assertStringStartsWith('https://www.archion.de/de/', $p->fullUrl());
    }

    public function testMissingFileReturnsNull(): void
    {
        $lookup = new ArchionParishLookup('/tmp/does-not-exist.json');
        self::assertNull($lookup->nearestWithin(49.0, 9.0, 100.0));
    }
}
