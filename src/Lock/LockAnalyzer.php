<?php

declare(strict_types=1);

namespace Satiate\Lock;

final class LockAnalyzer
{
    /**
     * Analyze a composer.lock file and derive recommended version constraints.
     *
     * @return list<PackageConstraint>
     */
    public function analyze(string $lockPath, string $projectDir): array
    {
        if (! is_file($lockPath)) {
            throw new \RuntimeException(\sprintf('Lock file not found: %s', $lockPath));
        }

        $contents = file_get_contents($lockPath);

        if ($contents === false) {
            throw new \RuntimeException(\sprintf('Failed to read lock file: %s', $lockPath));
        }

        $data = json_decode($contents, true);

        if (! is_array($data) || ! isset($data['packages']) || ! is_array($data['packages'])) {
            throw new \RuntimeException(\sprintf('Invalid lock file format: %s', $lockPath));
        }

        $constraints = [];

        foreach ($data['packages'] as $package) {
            if (! isset($package['name'], $package['version'])) {
                continue;
            }

            $name = $package['name'];
            $version = ltrim($package['version'], 'v');
            $isRisky = $this->assessReversionRisk($name, $projectDir);

            $constraints[] = new PackageConstraint(
                name: $name,
                version: $version,
                constraint: \sprintf('>=%s', $version),
                isRisky: $isRisky,
            );
        }

        return $constraints;
    }

    private function assessReversionRisk(string $packageName, string $projectDir): bool
    {
        $lockPath = $projectDir . '/composer.lock';

        if (! is_file($lockPath)) {
            return false;
        }

        $blameOutput = shell_exec(\sprintf(
            'cd %s && git blame --line-porcelain composer.lock 2>/dev/null',
            escapeshellarg($projectDir),
        ));

        if ($blameOutput === null || $blameOutput === '') {
            return false;
        }

        $lines = explode("\n", $blameOutput);
        $commitsTouching = [];

        foreach ($lines as $line) {
            if (str_contains($line, $packageName)) {
                $commitHash = substr($line, 0, 40);

                if (ctype_xdigit($commitHash) && strlen($commitHash) === 40) {
                    $commitsTouching[$commitHash] = true;
                }
            }
        }

        return count($commitsTouching) >= 3;
    }
}
