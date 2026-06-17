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

    public function __construct(
        private readonly SatisConfig $config,
        private readonly string $outputDir,
        private readonly bool $includeDev,
        private readonly bool $runAudit,
    ) {}

    public function run(): int
    {
        $outputDir = $this->outputDir;

        if (! is_dir($outputDir)) {
            if (! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
                throw new \RuntimeException(\sprintf('Failed to create output directory: %s', $outputDir));
            }
        }

        $this->resolvePackages();

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

        $auditor = new Auditor();
        $totalFindings = 0;

        foreach ($this->resolvedPackages as $package) {
            $packageName = $package->getPrettyName();
            $version = $package->getPrettyVersion();

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
        }
    }

    private function rmdir(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
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

        $minimumStability = 'stable';
        $repositorySet = new RepositorySet($minimumStability, [], [], [], []);

        foreach ($rm->getRepositories() as $repository) {
            $repositorySet->addRepository($repository);
        }

        $pool = $repositorySet->createPoolWithAllPackages();

        foreach ($pool->getPackages() as $package) {
            if ($package instanceof CompletePackageInterface && $package->getType() !== 'metapackage') {
                $this->resolvedPackages[] = $package;
            }
        }
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
        $distDir = $outputDir . '/dist';

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
                'zip',
            );
            $expectedPath = $distDir . '/' . $expectedFilename;

            if (is_file($expectedPath)) {
                continue;
            }

            $createdPath = $archiveManager->archive($package, 'zip', $distDir);

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

        $data = [
            'packages' => $grouped,
            'metadata-url' => '/p/%package%.json',
            'available-packages' => array_keys($grouped),
        ];

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
