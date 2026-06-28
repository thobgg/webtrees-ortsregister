<?php

declare(strict_types=1);

namespace Ortsregister\Tests\Unit\Service;

use Ortsregister\Service\ArchionLinker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(ArchionLinker::class)]
final class ArchionLinkerTest extends TestCase
{
    private ArchionLinker $linker;

    protected function setUp(): void
    {
        $this->linker = new ArchionLinker();
    }

    public function testIsHttpUrlAcceptsHttpAndHttps(): void
    {
        $m = $this->method('isHttpUrl');
        self::assertTrue($m->invoke($this->linker, 'http://example.com'));
        self::assertTrue($m->invoke($this->linker, 'https://www.archion.de/de/foo'));
    }

    public function testIsHttpUrlRejectsScriptSchemes(): void
    {
        $m = $this->method('isHttpUrl');
        self::assertFalse($m->invoke($this->linker, 'javascript:alert(1)'));
        self::assertFalse($m->invoke($this->linker, 'data:text/html;base64,...'));
        self::assertFalse($m->invoke($this->linker, 'file:///etc/passwd'));
        self::assertFalse($m->invoke($this->linker, 'not-a-url'));
        self::assertFalse($m->invoke($this->linker, ''));
    }

    public function testIsValidPlaceNameAcceptsNormalNames(): void
    {
        $m = $this->method('isValidPlaceName');
        self::assertTrue($m->invoke($this->linker, 'Brackenheim'));
        self::assertTrue($m->invoke($this->linker, 'Bönnigheim'));
        self::assertTrue($m->invoke($this->linker, 'Saint-Étienne'));
    }

    public function testIsValidPlaceNameRejectsTraversal(): void
    {
        $m = $this->method('isValidPlaceName');
        self::assertFalse($m->invoke($this->linker, ''));
        self::assertFalse($m->invoke($this->linker, '../etc'));
        self::assertFalse($m->invoke($this->linker, 'foo/bar'));
        self::assertFalse($m->invoke($this->linker, 'foo\\bar'));
    }

    private function method(string $name): ReflectionMethod
    {
        $m = new ReflectionMethod(ArchionLinker::class, $name);
        $m->setAccessible(true);
        return $m;
    }
}
