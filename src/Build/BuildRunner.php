<?php

declare(strict_types=1);

namespace Satiate\Build;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Package\CompletePackageInterface;
use Composer\Repository\RepositorySet;
use Satiate\Audit\Auditor;
use Satiate\Config\SatisConfig;

final class BuildRunner
{
    /**
     * @var list<CompletePackageInterface>
     */
    private array $resolvedPackages = [];
    private ?\Composer\Composer $composer = null;

    public function __construct(
        private readonly SatisConfig $config,
        private readonly string $outputDir,
        private readonly bool $includeDev,
        private readonly bool $runAudit,
    ) {}

    public int $lastAuditFindings = 0;

    public function run(): int
    {
        $outputDir = $this->outputDir;

        if (! is_dir($outputDir)) {
            if (! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
                throw new \RuntimeException(\sprintf('Failed to create output directory: %s', $outputDir));
            }
        }

        $this->resolvePackages();

        $this->applyVersionPruning();

        $this->downloadDistArchives($outputDir);

        $serialized = $this->serializePackages($outputDir);

        $this->generatePackagesJson($outputDir, $serialized);

        $this->generateWebUi($outputDir, $serialized);

        if ($this->runAudit) {
            $this->auditStep($outputDir);
        }

        return 0;
    }

    private function auditStep(string $outputDir): void
    {
        $cacheDir = $outputDir . '/.satiate-cache';

        if (! is_dir($cacheDir)) {
            if (! mkdir($cacheDir, 0755, true) && ! is_dir($cacheDir)) {
                throw new \RuntimeException(\sprintf('Failed to create cache directory: %s', $cacheDir));
            }
        }

        $auditedPath = $cacheDir . '/audited-versions.json';
        $audited = [];

        if (is_file($auditedPath)) {
            $content = file_get_contents($auditedPath);

            if ($content !== false) {
                $decoded = json_decode($content, true);

                if (is_array($decoded)) {
                    $audited = $decoded;
                }
            }
        }

        $auditor = new Auditor();
        $totalFindings = 0;
        $newlyAudited = [];

        foreach ($this->resolvedPackages as $package) {
            $packageName = $package->getPrettyName();
            $version = $package->getPrettyVersion();

            $packageKey = $packageName . ':' . $version;

            if (isset($audited[$packageKey])) {
                continue;
            }

            $distDir = $outputDir . '/dist';
            $archiveFilename = \sprintf(
                '%s-%s.%s',
                str_replace('/', '-', $packageName),
                $version,
                'zip',
            );
            $archivePath = $distDir . '/' . $archiveFilename;

            if (! is_file($archivePath)) {
                continue;
            }

            $tmpDir = $cacheDir . '/extract_' . bin2hex(random_bytes(4));

            if (! mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
                continue;
            }

            $zip = new \ZipArchive();

            if ($zip->open($archivePath) === true) {
                $zip->extractTo($tmpDir);
                $zip->close();

                $finder = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                );

                foreach ($finder as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $results = $auditor->auditFile($packageName, $version, $file->getPathname());

                        $totalFindings += \count($results);
                    }
                }

                $this->rmdir($tmpDir);
            }

            $newlyAudited[] = $packageKey;
        }

        if ($newlyAudited !== []) {
            foreach ($newlyAudited as $key) {
                $audited[$key] = [
                    'audited_at' => date('c'),
                ];
            }

            file_put_contents($auditedPath, json_encode($audited, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->lastAuditFindings = $totalFindings;
    }

    private function rmdir(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
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

    private function resolvePackages(): void
    {
        $io = new NullIO();
        $composer = Factory::create($io, [
            'repositories' => [
                'packagist' => false,
            ],
        ], false, true);
        $rm = $composer->getRepositoryManager();

        foreach ($this->config->repositories as $repoConfig) {
            $repository = $rm->createRepository($repoConfig['type'], $repoConfig);
            $rm->addRepository($repository);
        }

        $minimumStability = 'dev';
        $repositorySet = new RepositorySet($minimumStability, [], [], [], []);

        foreach ($rm->getRepositories() as $repository) {
            $repositorySet->addRepository($repository);
        }

        if ($this->config->requireAll) {
            try {
                $pool = $repositorySet->createPoolWithAllPackages();
            } catch (\LogicException $e) {
                $allNames = $this->filterPackageNames($this->collectAllPackageNames($rm));
                $pool = $repositorySet->createPoolForPackages($allNames);
            }
        } else {
            $initialNames = array_keys($this->config->require);
            $allNames = $this->filterPackageNames($initialNames);

            $pool = $repositorySet->createPoolForPackages($allNames);

            $seen = [];

            foreach ($pool->getPackages() as $pkg) {
                $name = $pkg->getPrettyName();

                if (isset($seen[$name])) {
                    continue;
                }

                $seen[$name] = true;

                if ($this->config->requireDependencies) {
                    foreach ($pkg->getRequires() as $link) {
                        $depName = $link->getTarget();

                        if ($this->isPlatformPackage($depName)) {
                            continue;
                        }

                        if (! in_array($depName, $allNames, true)) {
                            $allNames[] = $depName;
                        }
                    }
                }

                if ($this->includeDev || $this->config->requireDevDependencies) {
                    foreach ($pkg->getDevRequires() as $link) {
                        $depName = $link->getTarget();

                        if ($this->isPlatformPackage($depName)) {
                            continue;
                        }

                        if (! in_array($depName, $allNames, true)) {
                            $allNames[] = $depName;
                        }
                    }
                }
            }

            $pool = $repositorySet->createPoolForPackages($allNames);
        }

        foreach ($pool->getPackages() as $package) {
            if (! $package instanceof CompletePackageInterface || $package->getType() === 'metapackage') {
                continue;
            }

            if (! $this->config->requireAll && $this->config->require !== []) {
                $packageName = $package->getPrettyName();

                if (isset($this->config->require[$packageName])) {
                    $constraintStr = $this->config->require[$packageName];

                    if (! $this->versionMatchesConstraint($package, $constraintStr)) {
                        continue;
                    }
                }
            }

            $this->resolvedPackages[] = $package;
        }
    }

    private function versionMatchesConstraint(CompletePackageInterface $package, string $constraintStr): bool
    {
        try {
            $versionParser = new \Composer\Semver\VersionParser();

            $requireConstraint = $versionParser->parseConstraints($constraintStr);
            $packageConstraint = $versionParser->parseConstraints($package->getVersion());

            return $requireConstraint->matches($packageConstraint);
        } catch (\UnexpectedValueException) {
            return true;
        }
    }

    private function applyVersionPruning(): void
    {
        $maxVersions = $this->config->maxVersionsPerPackage;

        if ($maxVersions <= 0) {
            return;
        }

        $grouped = [];

        foreach ($this->resolvedPackages as $package) {
            $name = $package->getPrettyName();
            $grouped[$name][] = $package;
        }

        $pruned = [];

        foreach ($grouped as $name => $versions) {
            if (\count($versions) <= $maxVersions) {
                foreach ($versions as $pkg) {
                    $pruned[] = $pkg;
                }

                continue;
            }

            $sorted = $versions;
            usort($sorted, function (CompletePackageInterface $a, CompletePackageInterface $b): int {
                return version_compare($b->getVersion(), $a->getVersion());
            });

            for ($i = 0; $i < $maxVersions; $i++) {
                $pruned[] = $sorted[$i];
            }
        }

        $this->resolvedPackages = $pruned;
    }

    /**
     * @return list<string>
     */
    private function collectAllPackageNames(\Composer\Repository\RepositoryManager $rm): array
    {
        $names = [];

        foreach ($rm->getRepositories() as $repo) {
            try {
                foreach ($repo->getPackageNames() as $name) {
                    $names[] = $name;
                }
            } catch (\Exception) {
                continue;
            }
        }

        return array_values(array_unique($names));
    }

    private function isPlatformPackage(string $packageName): bool
    {
        return str_starts_with($packageName, 'php')
            || str_starts_with($packageName, 'ext-')
            || str_starts_with($packageName, 'lib-')
            || \in_array($packageName, ['php', 'composer-plugin-api', 'composer-runtime-api', 'composer-installers'], true);
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function filterPackageNames(array $names): array
    {
        return array_values(array_filter($names, fn(string $name): bool => ! $this->isPlatformPackage($name)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializePackages(string $outputDir): array
    {
        $result = [];

        foreach ($this->resolvedPackages as $package) {
            $data = $this->packageToArray($package, $outputDir);

            if ($data !== null) {
                $result[] = $data;
            }
        }

        return $result;
    }

    private function downloadDistArchives(string $outputDir): void
    {
        $archiveConfig = $this->config->archive;
        $distDirName = ($archiveConfig['directory'] ?? 'dist');
        $archiveFormat = ($archiveConfig['format'] ?? 'zip');
        $distDir = $outputDir . '/' . $distDirName;

        if (! is_dir($distDir)) {
            if (! mkdir($distDir, 0755, true) && ! is_dir($distDir)) {
                throw new \RuntimeException(\sprintf('Failed to create dist directory: %s', $distDir));
            }
        }

        $io = new NullIO();
        $composer = Factory::create($io, [
            'repositories' => [
                'packagist' => false,
            ],
        ], false, true);

        $archiveManager = $composer->getArchiveManager();

        foreach ($this->resolvedPackages as $package) {
            $packageName = $package->getPrettyName();

            $expectedFilename = \sprintf(
                '%s-%s.%s',
                str_replace('/', '-', $packageName),
                $package->getPrettyVersion(),
                $archiveFormat,
            );
            $expectedPath = $distDir . '/' . $expectedFilename;

            if (is_file($expectedPath)) {
                continue;
            }

            $createdPath = $archiveManager->archive($package, $archiveFormat, $distDir);

            if ($createdPath !== $expectedPath && is_file($createdPath)) {
                rename($createdPath, $expectedPath);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function packageToArray(CompletePackageInterface $package, string $outputDir): ?array
    {
        $requires = [];

        foreach ($package->getRequires() as $link) {
            $requires[$link->getTarget()] = $link->getPrettyConstraint();
        }

        $devRequires = [];

        foreach ($package->getDevRequires() as $link) {
            $devRequires[$link->getTarget()] = $link->getPrettyConstraint();
        }

        $suggests = $package->getSuggests();

        $source = null;

        if ($package->getSourceType() !== null) {
            $source = [
                'type' => $package->getSourceType(),
                'url' => $package->getSourceUrl(),
                'reference' => $package->getSourceReference(),
            ];
        }

        $dist = null;

        if ($package->getDistType() !== null) {
            $archiveFilename = \sprintf(
                '%s-%s.%s',
                str_replace('/', '-', $package->getPrettyName()),
                $package->getPrettyVersion(),
                'zip',
            );

            $dist = [
                'type' => 'zip',
                'url' => $this->archiveUrlForPackage($outputDir, $archiveFilename),
                'reference' => $package->getDistReference(),
                'shasum' => $this->computeSha256($outputDir . '/dist/' . $archiveFilename),
            ];
        }

        $data = [
            'name' => $package->getPrettyName(),
            'version' => $package->getPrettyVersion(),
            'version_normalized' => $package->getVersion(),
            'type' => $package->getType(),
            'description' => $package->getDescription(),
            'keywords' => $package->getKeywords(),
            'homepage' => $package->getHomepage(),
            'license' => $package->getLicense(),
            'authors' => $package->getAuthors(),
            'source' => $source,
            'dist' => $dist,
            'require' => $requires,
            'require-dev' => $devRequires,
            'suggest' => $suggests,
            'autoload' => $package->getAutoload(),
            'autoload-dev' => $package->getDevAutoload(),
            'extra' => $package->getExtra(),
            'time' => $package->getReleaseDate()?->format('Y-m-d\TH:i:sP'),
        ];

        return array_filter($data, fn(mixed $value): bool => $value !== null && $value !== []);
    }

    private function archiveUrlForPackage(string $outputDir, string $archiveFilename): string
    {
        if ($this->config->archive !== null && isset($this->config->archive['prefix-url'])) {
            return \sprintf(
                '%s/dist/%s',
                rtrim($this->config->archive['prefix-url'], '/'),
                $archiveFilename,
            );
        }

        return \sprintf(
            '%s/dist/%s',
            rtrim($this->config->homepage, '/'),
            $archiveFilename,
        );
    }

    private function computeSha256(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return $hash !== false ? $hash : null;
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function generatePackagesJson(string $outputDir, array $packages): void
    {
        $grouped = [];

        foreach ($packages as $package) {
            $name = $package['name'];
            $version = $package['version'];
            $grouped[$name][$version] = $package;
        }

        $this->generateProviderFiles($outputDir, $grouped);

        $includeHash = $this->generateIncludeFiles($outputDir, $grouped);

        $data = [
            'packages' => $grouped,
            'metadata-url' => '/p/%package%.json',
            'available-packages' => array_keys($grouped),
        ];

        if ($includeHash !== null) {
            $includePath = \sprintf('include/all$%s.json', $includeHash);
            $data['includes'] = [
                $includePath => [
                    'sha1' => $includeHash,
                ],
            ];
        }

        $jsonFile = new JsonFile($outputDir . '/packages.json');
        $jsonFile->write($data);
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $grouped
     */
    private function generateProviderFiles(string $outputDir, array $grouped): void
    {
        $providerDir = $outputDir . '/p';

        if (! is_dir($providerDir)) {
            if (! mkdir($providerDir, 0755, true) && ! is_dir($providerDir)) {
                throw new \RuntimeException(\sprintf('Failed to create provider directory: %s', $providerDir));
            }
        }

        foreach ($grouped as $packageName => $versions) {
            $minified = MetadataMinifier::minify($versions);

            $providerData = [
                'packages' => [
                    $packageName => $minified,
                ],
            ];

            $packagePath = str_replace('/', '$', $packageName);
            $providerFile = new JsonFile($providerDir . '/' . $packagePath . '.json');
            $providerFile->write($providerData);
        }
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $grouped
     * @return non-empty-string|null
     */
    private function generateIncludeFiles(string $outputDir, array $grouped): ?string
    {
        $includeDir = $outputDir . '/include';

        if (! is_dir($includeDir)) {
            if (! mkdir($includeDir, 0755, true) && ! is_dir($includeDir)) {
                throw new \RuntimeException(\sprintf('Failed to create include directory: %s', $includeDir));
            }
        }

        if ($grouped === []) {
            return null;
        }

        $includeData = [
            'packages' => $grouped,
        ];
        $encoded = json_encode($includeData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = sha1($encoded);

        $includePath = \sprintf('include/all$%s.json', $hash);
        file_put_contents($outputDir . '/' . $includePath, $encoded);

        return $hash;
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function generateWebUi(string $outputDir, array $packages): void
    {
        $repoName = $this->config->name;
        $homepage = $this->config->homepage;

        $rows = '';

        foreach ($packages as $pkg) {
            $name = $pkg['name'] ?? '';
            $version = $pkg['version'] ?? '';
            $description = $pkg['description'] ?? '';
            $type = $pkg['type'] ?? '';
            $license = \is_array($pkg['license'] ?? null) ? \implode(', ', $pkg['license']) : ($pkg['license'] ?? '');

            $distUrl = $pkg['dist']['url'] ?? '';
            $distHtml = $distUrl !== '' ? \sprintf('<a href="%s">download</a>', $distUrl) : '';

            $rows .= \sprintf(
                "<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>\n",
                \htmlspecialchars($name),
                \htmlspecialchars($version),
                \htmlspecialchars($description),
                \htmlspecialchars($type),
                \htmlspecialchars($license),
                $distHtml,
            );
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$repoName}</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:1200px;margin:0 auto;padding:20px;color:#333}
h1{border-bottom:2px solid #4a9;padding-bottom:8px}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{padding:8px 12px;text-align:left;border-bottom:1px solid #ddd}
th{background:#f5f5f5;position:sticky;top:0}
tr:hover{background:#f0f8ff}
.meta{color:#666;font-size:14px}
a{color:#4a9;text-decoration:none}
</style>
</head>
<body>
<h1>{$repoName}</h1>
<p class="meta"><a href="{$homepage}">{$homepage}</a> &middot; <a href="packages.json">packages.json</a></p>
<table>
<thead><tr><th>Package</th><th>Version</th><th>Description</th><th>Type</th><th>License</th><th>Dist</th></tr></thead>
<tbody>
{$rows}
</tbody>
</table>
</body>
</html>
HTML;

        file_put_contents($outputDir . '/index.html', $html);
    }
}
