<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\WikimediaPlaceClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(WikimediaPlaceClient::class)]
final class WikimediaPlaceClientTest extends TestCase
{
    public function testDistanceHamburgBerlinIsAbout250km(): void
    {
        $client = $this->makeClient();
        $m = new ReflectionMethod(WikimediaPlaceClient::class, 'distanceKm');
        $m->setAccessible(true);
        // Hamburg ~53.55, 9.99 — Berlin ~52.52, 13.40
        $km = $m->invoke($client, 53.55, 9.99, 52.52, 13.40);
        self::assertGreaterThan(240.0, $km);
        self::assertLessThan(260.0, $km);
    }

    public function testDistanceZeroForSamePoint(): void
    {
        $client = $this->makeClient();
        $m = new ReflectionMethod(WikimediaPlaceClient::class, 'distanceKm');
        $m->setAccessible(true);
        $km = $m->invoke($client, 48.0, 9.0, 48.0, 9.0);
        self::assertSame(0.0, $km);
    }

    public function testParseImageInfoExtractsThumbAndLicense(): void
    {
        $client = $this->makeClient();
        $m = new ReflectionMethod(WikimediaPlaceClient::class, 'parseImageInfo');
        $m->setAccessible(true);
        $page = [
            'title' => 'File:Brackenheim_Marktplatz.jpg',
            'imageinfo' => [[
                'thumburl'       => 'https://upload.wikimedia.org/.../800px-Brackenheim_Marktplatz.jpg',
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Brackenheim_Marktplatz.jpg',
                'extmetadata' => [
                    'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                    'Artist'           => ['value' => '<a href="...">User</a>'],
                ],
            ]],
        ];
        $img = $m->invoke($client, $page);
        self::assertNotNull($img);
        self::assertSame('https://upload.wikimedia.org/.../800px-Brackenheim_Marktplatz.jpg', $img->thumbUrl);
        self::assertSame('Brackenheim_Marktplatz.jpg', $img->title);
        self::assertSame('CC BY-SA 4.0', $img->license);
    }

    public function testParseImageInfoReturnsNullForMissingImageinfo(): void
    {
        $client = $this->makeClient();
        $m = new ReflectionMethod(WikimediaPlaceClient::class, 'parseImageInfo');
        $m->setAccessible(true);
        self::assertNull($m->invoke($client, ['title' => 'File:X.jpg']));
        self::assertNull($m->invoke($client, ['title' => 'File:X.jpg', 'imageinfo' => []]));
    }

    private function makeClient(): WikimediaPlaceClient
    {
        // ApcuCacheService ohne APCu-Backend wäre umständlich; wir nutzen
        // hier nur reine Methoden, die den Cache nicht berühren. Trotzdem
        // brauchen wir eine Instanz für den Konstruktor — anonyme Subklasse.
        $cache = new class extends \Ortsregister\Cache\ApcuCacheService {
            public function __construct() {} // bewusst Parent-Konstruktor übergehen
        };
        return new WikimediaPlaceClient($cache);
    }
}
