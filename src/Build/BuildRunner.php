<?php

declare(strict_types=1);

namespace Satiate\Build;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Package\CompletePackageInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Psl\Type;
use Satiate\Audit\Auditor;
use Satiate\Audit\AuditResult;
use Satiate\Audit\AuditSummary;
use Satiate\Audit\Severity;
use Satiate\Audit\VersionCapabilityDiff;
use Satiate\Config\SatisConfig;

final class BuildRunner
{
    /**
     * @var list<CompletePackageInterface>
     */
    private array $resolvedPackages = [];

    public AuditSummary $lastAuditSummary;

    /**
     * Capabilities a newly-audited version gained relative to the version before it.
     *
     * @var list<array{package: string, version: string, previousVersion: string, capability: string}>
     */
    public array $lastCapabilityChanges = [];

    public function __construct(
        private readonly SatisConfig $config,
        private readonly string $outputDir,
        private readonly bool $includeDev,
        private readonly bool $runAudit,
        private readonly bool $useAuditCache,
    ) {
        $this->lastAuditSummary = new AuditSummary();
    }

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
        $auditedPath = $cacheDir . '/audited-versions.json';
        $fingerprintsPath = $cacheDir . '/capability-fingerprints.json';
        $audited = [];

        /** @var array<string, array<string, list<string>>> $fingerprints */
        $fingerprints = [];

        // With the cache disabled, every resolved package is audited every run (and nothing is
        // persisted) — the deterministic behaviour you want when gating CI on `--fail-on`.
        if ($this->useAuditCache) {
            if (! is_dir($cacheDir)) {
                if (! mkdir($cacheDir, 0755, true) && ! is_dir($cacheDir)) {
                    throw new \RuntimeException(\sprintf('Failed to create cache directory: %s', $cacheDir));
                }
            }

            if (is_file($auditedPath)) {
                $content = file_get_contents($auditedPath);

                if ($content !== false) {
                    $decoded = json_decode($content, true);

                    if (is_array($decoded)) {
                        $audited = $decoded;
                    }
                }
            }

            $fingerprints = $this->loadFingerprints($fingerprintsPath);
        }

        $auditor = new Auditor();
        $summary = new AuditSummary();
        $newlyAudited = [];

        /** @var array<string, list<string>> $newVersions */
        $newVersions = [];

        foreach ($this->resolvedPackages as $package) {
            $packageName = $package->getPrettyName();
            $version = $package->getPrettyVersion();

            $packageKey = $packageName . ':' . $version;

            if (isset($audited[$packageKey])) {
                continue;
            }

            $distDir = $outputDir . '/' . $this->archiveDirName();
            $archivePath = $distDir . '/' . $this->archiveFileName($packageName, $version);

            if (! is_file($archivePath)) {
                continue;
            }

            $results = $auditor->auditArchive($packageName, $version, $archivePath);
            $summary->addAll($results);
            $fingerprints[$packageName][$version] = $this->capabilityFingerprint($results);
            $newVersions[$packageName][] = $version;

            $newlyAudited[] = $packageKey;
        }

        $this->lastCapabilityChanges = $this->detectCapabilityChanges($fingerprints, $newVersions);

        if ($this->useAuditCache && $newlyAudited !== []) {
            foreach ($newlyAudited as $key) {
                $audited[$key] = [
                    'audited_at' => date('c'),
                ];
            }

            file_put_contents($auditedPath, json_encode($audited, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            file_put_contents($fingerprintsPath, json_encode($fingerprints, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->lastAuditSummary = $summary;
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function loadFingerprints(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return [];
        }

        try {
            return Type\dict(Type\string(), Type\dict(Type\string(), Type\vec(Type\string())))
                ->coerce(json_decode($content, true));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Distinct Warning-or-worse finding patterns — the "capability fingerprint" of a version that
     * the cross-version diff compares. Info-level noise (assert, autoload.files, PSR-1, …) is
     * excluded so only security-relevant capability changes are tracked.
     *
     * @param list<AuditResult> $results
     * @return list<string>
     */
    private function capabilityFingerprint(array $results): array
    {
        $patterns = [];

        foreach ($results as $result) {
            if ($result->severity->rank() >= Severity::Warning->rank()) {
                $patterns[] = $result->pattern;
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Diff each package's versions and report capabilities a version audited THIS run introduced
     * relative to its predecessor — only for newly-audited versions, so an unchanged cached package
     * does not re-report its history every build. This is advisory (reported, never gates the
     * build): legitimate updates add capabilities too, so it is a review signal, not a finding.
     *
     * @param array<string, array<string, list<string>>> $fingerprints
     * @param array<string, list<string>> $newVersions
     * @return list<array{package: string, version: string, previousVersion: string, capability: string}>
     */
    private function detectCapabilityChanges(array $fingerprints, array $newVersions): array
    {
        $diff = new VersionCapabilityDiff();
        $changes = [];

        foreach ($fingerprints as $packageName => $versionMap) {
            $auditedVersions = $newVersions[$packageName] ?? [];

            if ($auditedVersions === []) {
                continue;
            }

            foreach ($diff->introduced($versionMap) as $change) {
                if (! \in_array($change['version'], $auditedVersions, true)) {
                    continue;
                }

                $changes[] = [
                    'package' => $packageName,
                    'version' => $change['version'],
                    'previousVersion' => $change['previousVersion'],
                    'capability' => $change['capability'],
                ];
            }
        }

        return $changes;
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

            /** @var array<string, list<string>> $depConstraints */
            $depConstraints = [];
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

                        $depConstraints[$depName][] = $link->getPrettyConstraint();

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

                        $depConstraints[$depName][] = $link->getPrettyConstraint();

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

            $packageName = $package->getPrettyName();

            if (! $this->config->requireAll && $this->config->require !== []) {
                if (isset($this->config->require[$packageName])) {
                    $constraintStr = $this->config->require[$packageName];

                    if (! $this->versionMatchesConstraint($package, $constraintStr)) {
                        continue;
                    }
                } elseif (isset($depConstraints[$packageName])) {
                    $matchesAny = false;

                    foreach ($depConstraints[$packageName] as $constraintStr) {
                        if ($this->versionMatchesConstraint($package, $constraintStr)) {
                            $matchesAny = true;

                            break;
                        }
                    }

                    if (! $matchesAny) {
                        continue;
                    }
                }
            }

            $this->resolvedPackages[] = $package;
        }
    }

    private function versionMatchesConstraint(CompletePackageInterface $package, string $constraintStr): bool
    {
        // Dev/branch versions (e.g. "dev-master", "9999999-dev") come from path or VCS
        // repositories that expose no release tag. Composer derives them from the enclosing
        // branch, so they carry no comparable semver and a numeric constraint like "^8.1" can
        // never match them. Dropping them would silently exclude every untagged local package
        // from the mirror, so keep them: the mirror should carry whatever the source provides
        // and let the consuming project decide via its own (dev) constraints.
        if ($package->isDev()) {
            return true;
        }

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
            if (! $repo instanceof ComposerRepository) {
                continue;
            }

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
        // Delegate to Composer's canonical, anchored platform-package check. A hand-rolled
        // str_starts_with($name, 'php') would also match real vendor packages such as
        // "phpunit/phpunit" or "phpstan/phpstan" and wrongly drop them from the mirror.
        return PlatformRepository::isPlatformPackage($packageName);
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
     * @return list<array{name: string, version: string, ...<string, mixed>}>
     */
    private function serializePackages(string $outputDir): array
    {
        $result = [];

        foreach ($this->resolvedPackages as $package) {
            $result[] = $this->packageToArray($package, $outputDir);
        }

        return $result;
    }

    private function downloadDistArchives(string $outputDir): void
    {
        $distDirName = $this->archiveDirName();
        $archiveFormat = $this->archiveFormatName();
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

            $expectedPath = $distDir . '/' . $this->archiveFileName($packageName, $package->getPrettyVersion());

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
     * @return array{name: string, version: string, ...<string, mixed>}
     */
    private function packageToArray(CompletePackageInterface $package, string $outputDir): array
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
            $archiveFilename = $this->archiveFileName($package->getPrettyName(), $package->getPrettyVersion());

            $dist = [
                'type' => $this->archiveFormatName(),
                'url' => $this->archiveUrlForPackage($outputDir, $archiveFilename),
                'reference' => $package->getDistReference(),
                'shasum' => $this->computeDistShasum($outputDir . '/' . $this->archiveDirName() . '/' . $archiveFilename),
            ];
        }

        $data = [
            'name' => $package->getPrettyName(),
            'version' => $package->getPrettyVersion(),
            'version_normalized' => $package->getVersion(),
            'uid' => \md5($package->getPrettyName() . '-' . $package->getPrettyVersion()),
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
        $archiveDir = $this->archiveDirName();

        if ($this->config->archive !== null && isset($this->config->archive['prefix-url'])) {
            return \sprintf(
                '%s/%s/%s',
                rtrim($this->config->archive['prefix-url'], '/'),
                $archiveDir,
                $archiveFilename,
            );
        }

        return \sprintf(
            '%s/%s/%s',
            rtrim($this->config->homepage, '/'),
            $archiveDir,
            $archiveFilename,
        );
    }

    private function archiveDirName(): string
    {
        return ($this->config->archive ?? [])['directory'] ?? 'dist';
    }

    private function archiveFormatName(): string
    {
        return ($this->config->archive ?? [])['format'] ?? 'zip';
    }

    /**
     * Build the on-disk / URL filename for a package's distribution archive. The name and version
     * are both slugified so nothing in them can escape the filename (see slugForFilename()).
     */
    private function archiveFileName(string $packageName, string $version): string
    {
        return \sprintf(
            '%s-%s.%s',
            $this->slugForFilename($packageName),
            $this->slugForFilename($version),
            $this->archiveFormatName(),
        );
    }

    /**
     * Flatten any run of characters that are not filename/URL-safe to a single '-'.
     *
     * A package name always contains a '/', and a path/VCS package built on a branch (e.g.
     * "fix/foo") gets a version like "dev-fix/foo"; left in the filename, a '/' would escape into a
     * subdirectory and a '/', '\\', space or other unsafe character would break the dist URL. Only
     * [A-Za-z0-9._-] is kept — enough for every package name and normalised version satiate emits.
     */
    private function slugForFilename(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);

        return $slug ?? $value;
    }

    private function computeDistShasum(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        // Composer verifies a dist's `shasum` field with hash_file('sha1', ...) — see
        // Composer\Downloader\FileDownloader — so it MUST be a SHA-1 digest. Emitting a SHA-256
        // here makes every download from the mirror fail "checksum verification of the file".
        $hash = hash_file('sha1', $path);

        return $hash !== false ? $hash : null;
    }

    /**
     * @param list<array{name: string, version: string, ...<string, mixed>}> $packages
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
        // No explicit mkdir: JsonFile::write() below creates the parent directory itself.
        $providerDir = $outputDir . '/p';

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

        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode include file data.');
        }

        $hash = sha1($encoded);

        $includePath = \sprintf('include/all$%s.json', $hash);
        file_put_contents($outputDir . '/' . $includePath, $encoded);

        return $hash;
    }

    /**
     * @param list<array{name: string, version: string, ...<string, mixed>}> $packages
     */
    private function generateWebUi(string $outputDir, array $packages): void
    {
        $repoName = \htmlspecialchars($this->config->name);
        $homepage = \htmlspecialchars($this->config->homepage);

        $stringType = Type\string();
        $licenseType = Type\union(Type\string(), Type\vec(Type\string()));
        $distType = Type\shape([
            'url' => Type\string(),
        ], true);

        $rows = '';

        foreach ($packages as $pkg) {
            $name = $pkg['name'];
            $version = $pkg['version'];
            $description = $stringType->coerce($pkg['description'] ?? '');
            $type = $stringType->coerce($pkg['type'] ?? '');

            $rawLicense = $licenseType->coerce($pkg['license'] ?? '');
            $license = \is_array($rawLicense) ? \implode(', ', $rawLicense) : $rawLicense;

            $dist = isset($pkg['dist']) ? $distType->coerce($pkg['dist']) : null;
            $distUrl = $dist['url'] ?? '';
            $distHtml = $distUrl !== '' ? \sprintf('<a href="%s">download</a>', \htmlspecialchars($distUrl)) : '';

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
