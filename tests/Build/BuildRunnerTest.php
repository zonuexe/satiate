<?php

declare(strict_types=1);

namespace Satiate\Tests\Build;

use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Semver\VersionParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psl\Type;
use Satiate\Build\BuildRunner;
use Satiate\Config\SatisConfig;

/**
 * Unit tests for the pure / filesystem-bound private methods of BuildRunner.
 *
 * The heavyweight public entry point (run()/resolvePackages()/downloadDistArchives())
 * needs a real Composer Factory, repositories and network access, so it is exercised
 * by the dogfood E2E test instead. Everything that can be driven without the network
 * is covered here via reflection.
 */
#[CoversClass(BuildRunner::class)]
final class BuildRunnerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/satiate_brunner_' . uniqid('', true);

        if (! mkdir($this->tmp, 0755, true) && ! is_dir($this->tmp)) {
            self::fail('Could not create temp dir for test.');
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $this->rmrf($this->tmp);
        }
    }

    // ---------------------------------------------------------------------
    // isPlatformPackage / filterPackageNames
    // ---------------------------------------------------------------------

    public function testIsPlatformPackageTrueForPlatformPackages(): void
    {
        $runner = $this->makeRunner();

        foreach (['php', 'php-64bit', 'php-ipv6', 'hhvm', 'ext-json', 'ext-mbstring', 'lib-curl', 'composer', 'composer-plugin-api', 'composer-runtime-api'] as $name) {
            self::assertTrue($this->invoke($runner, 'isPlatformPackage', $name), $name . ' should be a platform package');
        }
    }

    public function testIsPlatformPackageFalseForVendorPackages(): void
    {
        $runner = $this->makeRunner();

        // "composer-installers" is a real package, not a platform package — it must not be dropped.
        foreach (['monolog/monolog', 'symfony/console', 'psr/log', 'guzzlehttp/guzzle', 'composer-installers'] as $name) {
            self::assertFalse($this->invoke($runner, 'isPlatformPackage', $name), $name . ' should not be a platform package');
        }
    }

    /**
     * Regression guard: real vendor packages whose name merely begins with "php" must NOT be
     * treated as platform packages (a naive str_starts_with($name, 'php') used to drop them).
     */
    public function testIsPlatformPackageDoesNotMatchPhpVendorPrefix(): void
    {
        $runner = $this->makeRunner();

        self::assertFalse($this->invoke($runner, 'isPlatformPackage', 'phpunit/phpunit'));
        self::assertFalse($this->invoke($runner, 'isPlatformPackage', 'phpstan/phpstan'));
        self::assertFalse($this->invoke($runner, 'isPlatformPackage', 'phpoffice/phpspreadsheet'));
    }

    public function testFilterPackageNamesRemovesOnlyPlatformPackagesAndReindexes(): void
    {
        $runner = $this->makeRunner();

        $result = $this->invoke($runner, 'filterPackageNames', [
            'php',
            'monolog/monolog',
            'ext-json',
            'phpunit/phpunit',
            'symfony/console',
            'composer-installers',
        ]);

        // Only the genuine platform packages (php, ext-json) are stripped; the php-prefixed
        // vendor package and composer-installers survive.
        self::assertSame(['monolog/monolog', 'phpunit/phpunit', 'symfony/console', 'composer-installers'], $result);
    }

    // ---------------------------------------------------------------------
    // archiveDirName / archiveFormatName / archiveUrlForPackage
    // ---------------------------------------------------------------------

    public function testArchiveDirNameAndFormatDefaults(): void
    {
        $runner = $this->makeRunner($this->config());

        self::assertSame('dist', $this->invoke($runner, 'archiveDirName'));
        self::assertSame('zip', $this->invoke($runner, 'archiveFormatName'));
    }

    public function testArchiveDirNameAndFormatFromConfig(): void
    {
        $runner = $this->makeRunner($this->config([
            'archive' => [
                'directory' => 'archives',
                'format' => 'tar',
            ],
        ]));

        self::assertSame('archives', $this->invoke($runner, 'archiveDirName'));
        self::assertSame('tar', $this->invoke($runner, 'archiveFormatName'));
    }

    public function testArchiveUrlUsesHomepageWithTrailingSlashTrimmed(): void
    {
        $runner = $this->makeRunner($this->config([
            'homepage' => 'https://repo.example.com/',
        ]));

        self::assertSame(
            'https://repo.example.com/dist/acme-widget-1.0.0.zip',
            $this->invoke($runner, 'archiveUrlForPackage', '', 'acme-widget-1.0.0.zip'),
        );
    }

    public function testArchiveUrlPrefersPrefixUrlOverHomepage(): void
    {
        $runner = $this->makeRunner($this->config([
            'homepage' => 'https://repo.example.com',
            'archive' => [
                'directory' => 'pkgs',
                'format' => 'zip',
                'prefix-url' => 'https://cdn.example.com/mirror/',
            ],
        ]));

        self::assertSame(
            'https://cdn.example.com/mirror/pkgs/acme-widget-1.0.0.zip',
            $this->invoke($runner, 'archiveUrlForPackage', '', 'acme-widget-1.0.0.zip'),
        );
    }

    public function testArchiveUrlFallsBackToHomepageWhenArchiveHasNoPrefixUrl(): void
    {
        // archive is set (custom directory) but without prefix-url, so the homepage wins.
        $runner = $this->makeRunner($this->config([
            'homepage' => 'https://repo.example.com',
            'archive' => [
                'directory' => 'pkgs',
                'format' => 'zip',
            ],
        ]));

        self::assertSame(
            'https://repo.example.com/pkgs/acme-widget-1.0.0.zip',
            $this->invoke($runner, 'archiveUrlForPackage', '', 'acme-widget-1.0.0.zip'),
        );
    }

    // ---------------------------------------------------------------------
    // computeSha256
    // ---------------------------------------------------------------------

    public function testComputeSha256ReturnsNullForMissingFile(): void
    {
        $runner = $this->makeRunner();

        self::assertNull($this->invoke($runner, 'computeSha256', $this->tmp . '/does-not-exist.zip'));
    }

    public function testComputeSha256MatchesHashFile(): void
    {
        $runner = $this->makeRunner();
        $path = $this->tmp . '/payload.bin';
        file_put_contents($path, 'hello satiate');

        self::assertSame(hash_file('sha256', $path), $this->invoke($runner, 'computeSha256', $path));
    }

    // ---------------------------------------------------------------------
    // rmdir
    // ---------------------------------------------------------------------

    public function testRmdirRemovesNestedDirectoryTree(): void
    {
        $runner = $this->makeRunner();
        $root = $this->tmp . '/nested';
        mkdir($root . '/a/b', 0755, true);
        file_put_contents($root . '/top.txt', 'x');
        file_put_contents($root . '/a/mid.txt', 'y');
        file_put_contents($root . '/a/b/leaf.txt', 'z');

        $this->invoke($runner, 'rmdir', $root);

        self::assertDirectoryDoesNotExist($root);
    }

    // ---------------------------------------------------------------------
    // versionMatchesConstraint
    // ---------------------------------------------------------------------

    public function testVersionMatchesConstraintTrueWhenInRange(): void
    {
        $runner = $this->makeRunner();
        $package = $this->package('vendor/pkg', '2.3.0');

        self::assertTrue($this->invoke($runner, 'versionMatchesConstraint', $package, '^2.0'));
    }

    public function testVersionMatchesConstraintFalseWhenOutOfRange(): void
    {
        $runner = $this->makeRunner();
        $package = $this->package('vendor/pkg', '1.0.0');

        self::assertFalse($this->invoke($runner, 'versionMatchesConstraint', $package, '^2.0'));
    }

    public function testVersionMatchesConstraintReturnsTrueOnUnparsableConstraint(): void
    {
        $runner = $this->makeRunner();
        $package = $this->package('vendor/pkg', '1.0.0');

        // An unparsable constraint string throws inside Composer's parser; the method
        // swallows it and conservatively keeps the package.
        self::assertTrue($this->invoke($runner, 'versionMatchesConstraint', $package, 'this is not a constraint'));
    }

    // ---------------------------------------------------------------------
    // applyVersionPruning
    // ---------------------------------------------------------------------

    public function testApplyVersionPruningIsNoOpWhenMaxIsZero(): void
    {
        $runner = $this->makeRunner($this->config([
            'maxVersionsPerPackage' => 0,
        ]));
        $packages = [
            $this->package('vendor/pkg', '1.0.0'),
            $this->package('vendor/pkg', '2.0.0'),
            $this->package('vendor/pkg', '3.0.0'),
        ];
        $this->setResolved($runner, $packages);

        $this->invoke($runner, 'applyVersionPruning');

        self::assertSame($packages, $this->getResolved($runner));
    }

    public function testApplyVersionPruningKeepsHighestVersions(): void
    {
        $runner = $this->makeRunner($this->config([
            'maxVersionsPerPackage' => 2,
        ]));
        $this->setResolved($runner, [
            $this->package('vendor/pkg', '1.0.0'),
            $this->package('vendor/pkg', '3.0.0'),
            $this->package('vendor/pkg', '2.0.0'),
            $this->package('vendor/single', '1.5.0'),
        ]);

        $this->invoke($runner, 'applyVersionPruning');

        $kept = array_map(
            static fn (CompletePackage $p): string => $p->getPrettyName() . ':' . $p->getPrettyVersion(),
            $this->getResolved($runner),
        );

        // The two newest versions of vendor/pkg are kept (newest first), 1.0.0 is dropped,
        // and vendor/single (under the limit) is untouched.
        self::assertSame(['vendor/pkg:3.0.0', 'vendor/pkg:2.0.0', 'vendor/single:1.5.0'], $kept);
    }

    public function testApplyVersionPruningKeepsAllWhenUnderLimit(): void
    {
        $runner = $this->makeRunner($this->config([
            'maxVersionsPerPackage' => 5,
        ]));
        $packages = [
            $this->package('vendor/pkg', '1.0.0'),
            $this->package('vendor/pkg', '2.0.0'),
        ];
        $this->setResolved($runner, $packages);

        $this->invoke($runner, 'applyVersionPruning');

        self::assertCount(2, $this->getResolved($runner));
    }

    /**
     * Boundary case: count(versions) === maxVersionsPerPackage. The "<=" branch keeps every
     * version in its original order and then `continue`s to the next package. This uses two
     * packages so that:
     *  - the first (under-limit) package is NOT the last group — proving `continue` advances to
     *    the next group rather than breaking out of the loop;
     *  - the second package sits exactly at the limit in ascending order — proving the keep-all
     *    branch preserves order instead of re-sorting newest-first.
     */
    public function testApplyVersionPruningKeepsAllGroupsAtBoundaryInOrder(): void
    {
        $runner = $this->makeRunner($this->config([
            'maxVersionsPerPackage' => 2,
        ]));
        $this->setResolved($runner, [
            $this->package('vendor/aaa', '1.0.0'),
            $this->package('vendor/bbb', '1.0.0'),
            $this->package('vendor/bbb', '2.0.0'),
        ]);

        $this->invoke($runner, 'applyVersionPruning');

        $kept = array_map(
            static fn (CompletePackage $p): string => $p->getPrettyName() . ':' . $p->getPrettyVersion(),
            $this->getResolved($runner),
        );

        self::assertSame(['vendor/aaa:1.0.0', 'vendor/bbb:1.0.0', 'vendor/bbb:2.0.0'], $kept);
    }

    // ---------------------------------------------------------------------
    // serializePackages / packageToArray
    // ---------------------------------------------------------------------

    public function testSerializePackagesProducesExpectedShapeAndFiltersEmpties(): void
    {
        $runner = $this->makeRunner();
        $package = $this->package('acme/widget', '1.2.3');
        $package->setType('library');
        $package->setDescription('A handy widget');
        $package->setRequires([
            'psr/log' => new Link('acme/widget', 'psr/log', (new VersionParser())->parseConstraints('^1.0'), Link::TYPE_REQUIRE, '^1.0'),
        ]);
        $this->setResolved($runner, [$package]);

        $serialized = $this->asListOfDict($this->invoke($runner, 'serializePackages', $this->tmp));

        self::assertCount(1, $serialized);
        $data = $serialized[0];

        self::assertSame('acme/widget', $data['name']);
        self::assertSame('1.2.3', $data['version']);
        self::assertSame('1.2.3.0', $data['version_normalized']);
        self::assertSame(md5('acme/widget-1.2.3'), $data['uid']);
        self::assertSame('library', $data['type']);
        self::assertSame('A handy widget', $data['description']);
        self::assertSame([
            'psr/log' => '^1.0',
        ], $data['require']);

        // Null/empty fields are stripped out.
        self::assertArrayNotHasKey('dist', $data);
        self::assertArrayNotHasKey('source', $data);
        self::assertArrayNotHasKey('time', $data);
        self::assertArrayNotHasKey('keywords', $data);
    }

    public function testPackageToArraySerializesAllPopulatedMetadata(): void
    {
        $runner = $this->makeRunner();
        $package = $this->package('acme/widget', '1.2.3');
        $package->setKeywords(['util', 'widget']);
        $package->setHomepage('https://acme.example/widget');
        $package->setLicense(['MIT']);
        $package->setAuthors([[
            'name' => 'Jane Doe',
        ]]);
        $package->setAutoload([
            'psr-4' => [
                'Acme\\' => 'src/',
            ],
        ]);
        $package->setDevAutoload([
            'psr-4' => [
                'Acme\\Tests\\' => 'tests/',
            ],
        ]);
        $package->setExtra([
            'branch-alias' => [
                'dev-main' => '1.x-dev',
            ],
        ]);
        $package->setReleaseDate(new \DateTimeImmutable('2024-01-02T03:04:05+00:00'));
        $package->setSuggests([
            'ext-intl' => 'For localisation',
        ]);
        $package->setRequires([
            'psr/log' => new Link('acme/widget', 'psr/log', (new VersionParser())->parseConstraints('^1.0'), Link::TYPE_REQUIRE, '^1.0'),
        ]);
        $package->setDevRequires([
            'phpunit/phpunit' => new Link('acme/widget', 'phpunit/phpunit', (new VersionParser())->parseConstraints('^11.0'), Link::TYPE_DEV_REQUIRE, '^11.0'),
        ]);
        $this->setResolved($runner, [$package]);

        $data = $this->asListOfDict($this->invoke($runner, 'serializePackages', $this->tmp))[0];

        // Every populated metadata field is carried through under the expected key.
        self::assertSame(['util', 'widget'], $data['keywords']);
        self::assertSame('https://acme.example/widget', $data['homepage']);
        self::assertSame(['MIT'], $data['license']);
        self::assertSame([[
            'name' => 'Jane Doe',
        ]], $data['authors']);
        self::assertSame([
            'psr-4' => [
                'Acme\\' => 'src/',
            ],
        ], $data['autoload']);
        self::assertSame([
            'psr-4' => [
                'Acme\\Tests\\' => 'tests/',
            ],
        ], $data['autoload-dev']);
        self::assertSame([
            'branch-alias' => [
                'dev-main' => '1.x-dev',
            ],
        ], $data['extra']);
        self::assertSame('2024-01-02T03:04:05+00:00', $data['time']);
        self::assertSame([
            'ext-intl' => 'For localisation',
        ], $data['suggest']);
        self::assertSame([
            'psr/log' => '^1.0',
        ], $data['require']);
        self::assertSame([
            'phpunit/phpunit' => '^11.0',
        ], $data['require-dev']);
    }

    public function testSerializePackagesSerializesEveryResolvedPackage(): void
    {
        $runner = $this->makeRunner();
        $this->setResolved($runner, [
            $this->package('acme/widget', '1.0.0'),
            $this->package('acme/gadget', '2.0.0'),
        ]);

        $serialized = $this->asListOfDict($this->invoke($runner, 'serializePackages', $this->tmp));

        self::assertCount(2, $serialized);
        self::assertSame('acme/widget', $serialized[0]['name']);
        self::assertSame('acme/gadget', $serialized[1]['name']);
    }

    public function testPackageToArrayIncludesDistBlockWhenDistTypeSet(): void
    {
        $runner = $this->makeRunner($this->config([
            'homepage' => 'https://repo.example.com',
        ]));
        $package = $this->package('acme/widget', '1.2.3');
        $package->setDistType('zip');
        $package->setDistReference('deadbeef');
        $this->setResolved($runner, [$package]);

        $data = $this->asListOfDict($this->invoke($runner, 'serializePackages', $this->tmp))[0];

        self::assertArrayHasKey('dist', $data);
        $dist = $this->asDict($data['dist']);
        self::assertSame('zip', $dist['type']);
        self::assertSame('https://repo.example.com/dist/acme-widget-1.2.3.zip', $dist['url']);
        self::assertSame('deadbeef', $dist['reference']);
        // No archive file on disk, so the shasum could not be computed.
        self::assertNull($dist['shasum']);
    }

    public function testPackageToArrayComputesDistShasumFromArchiveOnDisk(): void
    {
        $runner = $this->makeRunner($this->config([
            'homepage' => 'https://repo.example.com',
        ]));
        // The archive must live at <outputDir>/dist/<name>-<version>.zip for the shasum to resolve.
        mkdir($this->tmp . '/dist', 0755, true);
        $archivePath = $this->tmp . '/dist/acme-widget-1.2.3.zip';
        file_put_contents($archivePath, 'pretend zip payload');

        $package = $this->package('acme/widget', '1.2.3');
        $package->setDistType('zip');
        $this->setResolved($runner, [$package]);

        $data = $this->asListOfDict($this->invoke($runner, 'serializePackages', $this->tmp))[0];
        $dist = $this->asDict($data['dist']);

        self::assertSame('https://repo.example.com/dist/acme-widget-1.2.3.zip', $dist['url']);
        // The shasum is the sha256 of exactly that on-disk archive.
        self::assertSame(hash_file('sha256', $archivePath), $dist['shasum']);
    }

    public function testPackageToArrayIncludesSourceBlockWhenSourceTypeSet(): void
    {
        $runner = $this->makeRunner();
        $package = $this->package('acme/widget', '1.2.3');
        $package->setSourceType('git');
        $package->setSourceUrl('https://github.com/acme/widget.git');
        $package->setSourceReference('abc123');
        $this->setResolved($runner, [$package]);

        $data = $this->asListOfDict($this->invoke($runner, 'serializePackages', $this->tmp))[0];

        self::assertSame([
            'type' => 'git',
            'url' => 'https://github.com/acme/widget.git',
            'reference' => 'abc123',
        ], $data['source']);
    }

    // ---------------------------------------------------------------------
    // generateIncludeFiles
    // ---------------------------------------------------------------------

    public function testGenerateIncludeFilesReturnsNullForEmptyGrouped(): void
    {
        $runner = $this->makeRunner();

        $result = $this->invoke($runner, 'generateIncludeFiles', $this->tmp, []);

        self::assertNull($result);
        // The include directory is still created, but no all$<hash>.json is written.
        self::assertDirectoryExists($this->tmp . '/include');
        self::assertSame([], glob($this->tmp . '/include/all$*.json'));
    }

    public function testGenerateIncludeFilesWritesHashedFileAndReturnsSha1(): void
    {
        $runner = $this->makeRunner();
        $grouped = [
            'acme/widget' => [
                '1.0.0' => [
                    'name' => 'acme/widget',
                    'version' => '1.0.0',
                ],
            ],
        ];

        $hash = $this->invoke($runner, 'generateIncludeFiles', $this->tmp, $grouped);

        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $hash);

        $expectedPath = $this->tmp . '/include/all$' . $hash . '.json';
        self::assertFileExists($expectedPath);

        $decoded = $this->decodeJsonObject($expectedPath);
        self::assertSame([
            'packages' => $grouped,
        ], $decoded);
        // The filename hash is the sha1 of the file contents.
        self::assertSame($hash, sha1((string) file_get_contents($expectedPath)));

        // The file is encoded with JSON_UNESCAPED_SLASHES, so the package name keeps its
        // literal slash rather than the escaped "acme\/widget".
        $raw = (string) file_get_contents($expectedPath);
        self::assertStringContainsString('"acme/widget"', $raw);
        self::assertStringNotContainsString('acme\\/widget', $raw);
    }

    // ---------------------------------------------------------------------
    // generateProviderFiles
    // ---------------------------------------------------------------------

    public function testGenerateProviderFilesWritesPerPackageJson(): void
    {
        $runner = $this->makeRunner();
        $grouped = [
            'acme/widget' => [
                '1.0.0' => [
                    'name' => 'acme/widget',
                    'version' => '1.0.0',
                ],
            ],
        ];

        $this->invoke($runner, 'generateProviderFiles', $this->tmp, $grouped);

        // The slash in the package name is encoded as '$' in the provider filename.
        $providerPath = $this->tmp . '/p/acme$widget.json';
        self::assertFileExists($providerPath);

        $decoded = $this->decodeJsonObject($providerPath);
        self::assertArrayHasKey('packages', $decoded);
        self::assertArrayHasKey('acme/widget', $this->asDict($decoded['packages']));
    }

    // ---------------------------------------------------------------------
    // generatePackagesJson
    // ---------------------------------------------------------------------

    public function testGeneratePackagesJsonWritesRootStructureWithIncludes(): void
    {
        $runner = $this->makeRunner();
        $packages = [
            [
                'name' => 'acme/widget',
                'version' => '1.0.0',
            ],
            [
                'name' => 'acme/widget',
                'version' => '2.0.0',
            ],
            [
                'name' => 'acme/gadget',
                'version' => '1.0.0',
            ],
        ];

        $this->invoke($runner, 'generatePackagesJson', $this->tmp, $packages);

        $decoded = $this->decodeJsonObject($this->tmp . '/packages.json');

        self::assertSame('/p/%package%.json', $decoded['metadata-url']);
        self::assertSame(['acme/widget', 'acme/gadget'], $decoded['available-packages']);
        // Two versions of acme/widget are grouped under one key.
        $widget = $this->asDict($this->asDict($decoded['packages'])['acme/widget']);
        self::assertArrayHasKey('1.0.0', $widget);
        self::assertArrayHasKey('2.0.0', $widget);

        // An includes entry referencing the hashed include file is present.
        self::assertArrayHasKey('includes', $decoded);
        $includes = $this->asDict($decoded['includes']);
        $includePath = array_key_first($includes);
        self::assertNotNull($includePath);
        self::assertSame(1, preg_match('#^include/all\$([0-9a-f]{40})\.json$#', $includePath, $m));
        self::assertSame($m[1], $this->asDict($includes[$includePath])['sha1']);
        self::assertFileExists($this->tmp . '/' . $includePath);

        // Provider files were written alongside.
        self::assertFileExists($this->tmp . '/p/acme$widget.json');
        self::assertFileExists($this->tmp . '/p/acme$gadget.json');
    }

    public function testGeneratePackagesJsonOmitsIncludesWhenNoPackages(): void
    {
        $runner = $this->makeRunner();

        $this->invoke($runner, 'generatePackagesJson', $this->tmp, []);

        $decoded = $this->decodeJsonObject($this->tmp . '/packages.json');

        self::assertSame([], $decoded['available-packages']);
        self::assertArrayNotHasKey('includes', $decoded);
    }

    // ---------------------------------------------------------------------
    // generateWebUi
    // ---------------------------------------------------------------------

    public function testGenerateWebUiWritesEscapedIndexHtml(): void
    {
        $runner = $this->makeRunner($this->config([
            'name' => 'My <Repo>',
            'homepage' => 'https://repo.example.com',
        ]));
        $packages = [
            [
                'name' => 'acme/<widget>',
                'version' => '1.0.0',
                'description' => 'Tom & Jerry',
                'type' => 'library',
                'license' => ['MIT', 'Apache-2.0'],
                'dist' => [
                    'url' => 'https://repo.example.com/dist/acme-widget-1.0.0.zip',
                ],
            ],
            [
                'name' => 'beta/gadget',
                'version' => '3.1.0',
                'type' => 'plugin',
            ],
        ];

        $this->invoke($runner, 'generateWebUi', $this->tmp, $packages);

        $html = (string) file_get_contents($this->tmp . '/index.html');

        // Repo name and package fields are HTML-escaped, not injected raw.
        self::assertStringContainsString('My &lt;Repo&gt;', $html);
        self::assertStringContainsString('acme/&lt;widget&gt;', $html);
        self::assertStringNotContainsString('acme/<widget>', $html);
        self::assertStringContainsString('Tom &amp; Jerry', $html);
        // An array license is rendered as a comma-joined string.
        self::assertStringContainsString('MIT, Apache-2.0', $html);
        // The type field is rendered in its own cell.
        self::assertStringContainsString('<td>library</td>', $html);
        // A dist url renders a download link.
        self::assertStringContainsString('<a href="https://repo.example.com/dist/acme-widget-1.0.0.zip">download</a>', $html);
        // Every package gets a row — the second one is appended, not overwriting the first.
        self::assertStringContainsString('beta/gadget', $html);
    }

    public function testGenerateWebUiOmitsDownloadLinkWhenNoDist(): void
    {
        $runner = $this->makeRunner();
        $packages = [
            [
                'name' => 'acme/widget',
                'version' => '1.0.0',
            ],
        ];

        $this->invoke($runner, 'generateWebUi', $this->tmp, $packages);

        $html = (string) file_get_contents($this->tmp . '/index.html');

        self::assertStringContainsString('acme/widget', $html);
        self::assertStringNotContainsString('download</a>', $html);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param array{name?: string, homepage?: string, archive?: ?array{directory: string, format: string, prefix-url?: string, skip-dev?: bool}, maxVersionsPerPackage?: int} $overrides
     */
    private function config(array $overrides = []): SatisConfig
    {
        return new SatisConfig(
            name: $overrides['name'] ?? 'Test Repo',
            homepage: $overrides['homepage'] ?? 'https://example.com',
            archive: $overrides['archive'] ?? null,
            maxVersionsPerPackage: $overrides['maxVersionsPerPackage'] ?? 0,
        );
    }

    private function makeRunner(?SatisConfig $config = null): BuildRunner
    {
        return new BuildRunner($config ?? $this->config(), $this->tmp, false, false);
    }

    private function package(string $name, string $prettyVersion): CompletePackage
    {
        return new CompletePackage($name, (new VersionParser())->normalize($prettyVersion), $prettyVersion);
    }

    private function invoke(BuildRunner $runner, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($runner, $method))->invoke($runner, ...$args);
    }

    /**
     * @param list<CompletePackage> $packages
     */
    private function setResolved(BuildRunner $runner, array $packages): void
    {
        (new \ReflectionProperty($runner, 'resolvedPackages'))->setValue($runner, $packages);
    }

    /**
     * @return list<CompletePackage>
     */
    private function getResolved(BuildRunner $runner): array
    {
        return Type\vec(Type\instance_of(CompletePackage::class))->coerce(
            (new \ReflectionProperty($runner, 'resolvedPackages'))->getValue($runner),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function asDict(mixed $value): array
    {
        return Type\dict(Type\string(), Type\mixed())->coerce($value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function asListOfDict(mixed $value): array
    {
        return Type\vec(Type\dict(Type\string(), Type\mixed()))->coerce($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $path): array
    {
        return $this->asDict(json_decode((string) file_get_contents($path), true));
    }

    private function rmrf(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
