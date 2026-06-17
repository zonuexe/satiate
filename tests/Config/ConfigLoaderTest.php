<?php

declare(strict_types=1);

namespace Satiate\Tests\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Config\ConfigLoader;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/fixtures';
    }

    public function testLoadValidConfig(): void
    {
        $config = ConfigLoader::load($this->fixtureDir . '/valid.json');

        self::assertSame('My Repository', $config->name);
        self::assertSame('https://packages.example.com', $config->homepage);
        self::assertCount(2, $config->repositories);
        self::assertCount(2, $config->require);
        self::assertFalse($config->requireAll);
        self::assertNotNull($config->archive);
    }

    public function testLoadMinimalConfig(): void
    {
        $config = ConfigLoader::load($this->fixtureDir . '/minimal.json');

        self::assertSame('Minimal Repo', $config->name);
        self::assertSame([
            'org/package' => '*',
        ], $config->require);
        self::assertNull($config->archive);
    }

    public function testLoadFileNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        ConfigLoader::load('/nonexistent/path/satis.json');
    }

    public function testLoadInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('parse');

        ConfigLoader::load($this->fixtureDir . '/invalid.json');
    }
}
