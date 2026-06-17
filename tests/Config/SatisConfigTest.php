<?php

declare(strict_types=1);

namespace Satiate\Tests\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Config\SatisConfig;

#[CoversClass(SatisConfig::class)]
final class SatisConfigTest extends TestCase
{
    public function testCreateWithFullConfig(): void
    {
        $config = new SatisConfig(
            name: 'My Repository',
            homepage: 'https://packages.example.com',
            repositories: [
                [
                    'type' => 'vcs',
                    'url' => 'https://github.com/org/pkg',
                ],
            ],
            require: [
                'org/pkg' => '^1.0',
            ],
            requireAll: false,
            requireDependencies: true,
            requireDevDependencies: false,
            archive: [
                'directory' => 'dist',
                'format' => 'zip',
                'prefix-url' => 'https://packages.example.com',
                'skip-dev' => true,
            ],
        );

        self::assertSame('My Repository', $config->name);
        self::assertSame('https://packages.example.com', $config->homepage);
        self::assertCount(1, $config->repositories);
        self::assertSame('vcs', $config->repositories[0]['type']);
        self::assertSame([
            'org/pkg' => '^1.0',
        ], $config->require);
        self::assertFalse($config->requireAll);
    }

    public function testCreateWithMinimalConfig(): void
    {
        $config = new SatisConfig(
            name: 'Minimal',
            homepage: 'https://minimal.example.com',
            require: [
                'org/pkg' => '*',
            ],
        );

        self::assertSame('Minimal', $config->name);
        self::assertFalse($config->requireAll);
        self::assertTrue($config->requireDependencies);
        self::assertFalse($config->requireDevDependencies);
        self::assertNull($config->archive);
        self::assertSame(0, $config->maxVersionsPerPackage);
    }

    public function testCreateWithVersionPruning(): void
    {
        $config = new SatisConfig(
            name: 'Pruned',
            homepage: 'https://pruned.example.com',
            require: [
                'org/pkg' => '*',
            ],
            maxVersionsPerPackage: 5,
        );

        self::assertSame(5, $config->maxVersionsPerPackage);
    }
}
