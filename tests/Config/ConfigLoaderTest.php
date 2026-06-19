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
        self::assertCount(0, $config->repositories);
        self::assertCount(1, $config->require);
        self::assertSame([
            'org/package-a' => '^1.0',
        ], $config->require);
        self::assertFalse($config->requireAll);
        self::assertTrue($config->requireDependencies);
        self::assertFalse($config->requireDevDependencies);
        self::assertSame(0, $config->maxVersionsPerPackage);
        self::assertNotNull($config->archive);
        self::assertSame('dist', $config->archive['directory']);
        self::assertSame('zip', $config->archive['format']);
        self::assertSame('https://packages.example.com', $config->archive['prefix-url'] ?? null);
        self::assertTrue($config->archive['skip-dev'] ?? null);
    }

    public function testLoadMinimalConfig(): void
    {
        $config = ConfigLoader::load($this->fixtureDir . '/minimal.json');

        self::assertSame('Minimal Repo', $config->name);
        self::assertSame('https://minimal.example.com', $config->homepage);
        self::assertSame([
            'org/package' => '*',
        ], $config->require);
        self::assertNull($config->archive);
        // Defaults when fields are absent
        self::assertCount(0, $config->repositories);
        self::assertFalse($config->requireAll);
        self::assertTrue($config->requireDependencies);
        self::assertFalse($config->requireDevDependencies);
        self::assertSame(0, $config->maxVersionsPerPackage);
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

    public function testLoadConfigWithVersionPruning(): void
    {
        $path = $this->fixtureDir . '/valid.json';
        $config = ConfigLoader::load($path);

        self::assertSame(0, $config->maxVersionsPerPackage);
    }

    /**
     * Tests that all fields with explicit non-default boolean values and
     * positive integer maxVersionsPerPackage are parsed correctly.
     * Kills: FalseValue/TrueValue mutants on requireAll, requireDependencies,
     * requireDevDependencies, and LogicalAndSingleSubExprNegation on maxVersionsPerPackage.
     */
    public function testLoadFullConfig(): void
    {
        $config = ConfigLoader::load($this->fixtureDir . '/full.json');

        self::assertSame('Full Repo', $config->name);
        self::assertSame('https://full.example.com', $config->homepage);

        // requireAll=true explicitly set (kills FalseValue default mutant on requireAll)
        self::assertTrue($config->requireAll);

        // requireDependencies=false explicitly set (kills TrueValue default mutant on requireDependencies)
        self::assertFalse($config->requireDependencies);

        // requireDevDependencies=true explicitly set (kills FalseValue default mutant on requireDevDependencies)
        self::assertTrue($config->requireDevDependencies);

        // maxVersionsPerPackage=5 (kills LogicalAndSingleSubExprNegation on maxVersionsPerPackage)
        self::assertSame(5, $config->maxVersionsPerPackage);

        // repositories parsed correctly (kills LogicalAndSingleSubExprNegation on repositories)
        self::assertCount(2, $config->repositories);
        self::assertSame('vcs', $config->repositories[0]['type']);
        self::assertSame('https://github.com/org/package-a', $config->repositories[0]['url']);
        self::assertSame('org/package-a', $config->repositories[0]['name'] ?? null);
        self::assertSame('vcs', $config->repositories[1]['type']);
        self::assertSame('https://github.com/org/package-b', $config->repositories[1]['url']);

        // archive fields including directory (kills ArrayItemRemoval of 'directory' key)
        self::assertNotNull($config->archive);
        self::assertSame('dist', $config->archive['directory']);
        self::assertSame('zip', $config->archive['format']);
        self::assertSame('https://full.example.com', $config->archive['prefix-url'] ?? null);
        self::assertFalse($config->archive['skip-dev'] ?? null);

        // require
        self::assertSame([
            'org/package-a' => '^1.0',
            'org/package-b' => '^2.0',
        ], $config->require);
    }

    /**
     * Tests that fields with wrong types fall back to their defaults.
     * Kills: LogicalAndSingleSubExprNegation mutants that negate the is_* checks,
     * LogicalAnd->|| mutants on repositories/require, and LogicalAnd->|| on name/homepage.
     */
    public function testLoadConfigWithBadTypesUsesDefaults(): void
    {
        $config = ConfigLoader::load($this->fixtureDir . '/bad-types.json');

        // name is int 12345 — not a string → fallback to '' (kills LogicalAnd->|| on line 39)
        self::assertSame('', $config->name);

        // homepage is int 67890 — not a string → fallback to ''
        self::assertSame('', $config->homepage);

        // repositories is a string "not-an-array" — not an array → empty (kills LogicalAndSingleSubExprNegation on repositories)
        self::assertCount(0, $config->repositories);

        // require is "not-an-array" string — not an array → empty (kills LogicalAnd->|| on require)
        self::assertCount(0, $config->require);
        self::assertSame([], $config->require);

        // requireAll is "not-bool" string — not a bool → fallback false (kills LogicalAndSingleSubExprNegation on requireAll)
        self::assertFalse($config->requireAll);

        // requireDependencies is "not-bool" string — not a bool → fallback true (kills LogicalAndSingleSubExprNegation on requireDependencies)
        self::assertTrue($config->requireDependencies);

        // requireDevDependencies is "not-bool" string — not a bool → fallback false (kills LogicalAndSingleSubExprNegation on requireDevDependencies)
        self::assertFalse($config->requireDevDependencies);

        // maxVersionsPerPackage is "not-int" string — not an int → fallback 0 (kills LogicalAndSingleSubExprNegation on maxVersionsPerPackage)
        self::assertSame(0, $config->maxVersionsPerPackage);

        // archive absent → null (kills LogicalAnd->|| on archive condition)
        self::assertNull($config->archive);
    }

    /**
     * Tests that archive is null when it exists but is missing required fields
     * (directory and/or format). Kills the LogicalAnd->|| mutant on the archive
     * isset condition (line 84).
     */
    public function testLoadArchiveMissingDirectoryFieldResultsInNullArchive(): void
    {
        $config = ConfigLoader::load($this->fixtureDir . '/archive-missing-fields.json');

        // archive exists in JSON but lacks 'directory' → must be null
        self::assertNull($config->archive);
    }

    /**
     * Tests that non-string values in the require array are skipped.
     * Kills the LogicalAnd->|| mutant on the inner require check (line 62).
     */
    public function testLoadRequireSkipsNonStringValues(): void
    {
        $config = ConfigLoader::load($this->fixtureDir . '/require-mixed-values.json');

        // Only string-keyed, string-valued entries are included
        self::assertSame([
            'valid/pkg' => '^1.0',
            'another/pkg' => '^2.0',
        ], $config->require);
    }

    /**
     * Tests that repositories entries are filtered by the full compound condition:
     * is_array($repo) && isset($repo['type'], $repo['url']) && is_string($repo['type']) && is_string($repo['url']).
     * Kills the three LogicalAnd->|| mutants on line 52.
     */
    public function testLoadRepositoriesSkipsMalformedEntries(): void
    {
        $config = ConfigLoader::load($this->fixtureDir . '/repos-mixed.json');

        // Only entries that are arrays AND have string type AND string url should be included.
        // From the fixture: "not-an-array-entry" (not array), {type-only} (no url),
        // {url-only} (no type), {type:123} (non-string type), {url:456} (non-string url) are all skipped.
        self::assertCount(2, $config->repositories);
        self::assertSame('vcs', $config->repositories[0]['type']);
        self::assertSame('https://github.com/org/valid-repo', $config->repositories[0]['url']);
        self::assertSame('composer', $config->repositories[1]['type']);
        self::assertSame('https://github.com/org/valid-repo-2', $config->repositories[1]['url']);
    }
}
