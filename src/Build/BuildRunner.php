<?php

declare(strict_types=1);

namespace Satiate\Build;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Repository\RepositorySet;
use Satiate\Config\SatisConfig;

final class BuildRunner
{
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

        $packages = $this->resolvePackages();

        $this->generatePackagesJson($outputDir, $packages);

        if ($this->runAudit) {
            // Audit step — not yet implemented
        }

        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolvePackages(): array
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

        $packages = [];

        foreach ($pool->getPackages() as $package) {
            $packageData = $this->packageToArray($package);

            if ($packageData !== null) {
                $packages[] = $packageData;
            }
        }

        return $packages;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function packageToArray(\Composer\Package\PackageInterface $package): ?array
    {
        if ($package->getType() === 'metapackage') {
            return null;
        }

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
            $dist = [
                'type' => $package->getDistType(),
                'url' => $package->getDistUrl(),
                'reference' => $package->getDistReference(),
                'shasum' => $package->getDistSha1Checksum(),
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

    private function generatePackagesJson(string $outputDir, array $packages): void
    {
        $grouped = [];

        foreach ($packages as $package) {
            $name = $package['name'];
            $version = $package['version'];
            $grouped[$name][$version] = $package;
        }

        $data = [
            'packages' => $grouped,
            'metadata-url' => '/p/%package%.json',
            'available-packages' => array_keys($grouped),
        ];

        $jsonFile = new JsonFile($outputDir . '/packages.json');
        $jsonFile->write($data);
    }
}
