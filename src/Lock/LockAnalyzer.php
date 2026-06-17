<?php

declare(strict_types=1);

namespace Satiate\Lock;

use Psl\Json;
use Psl\Type;

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

        try {
            $data = Json\typed($contents, Type\shape([
                'packages' => Type\vec(Type\shape([
                    'name' => Type\string(),
                    'version' => Type\string(),
                ], true)),
            ], true));
        } catch (Json\Exception\DecodeException $e) {
            throw new \RuntimeException(\sprintf('Invalid lock file format: %s', $lockPath), 0, $e);
        }

        $constraints = [];

        foreach ($data['packages'] as $package) {
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

        if ($blameOutput === null || $blameOutput === false || $blameOutput === '') {
            return false;
        }

        $lines = explode("\n", $blameOutput);
        $commitsTouching = [];
        $currentHash = null;

        foreach ($lines as $line) {
            if (preg_match('/^([0-9a-f]{40})\s/', $line, $matches)) {
                $currentHash = $matches[1];
            } elseif (str_starts_with($line, "\t") && $currentHash !== null) {
                if (str_contains($line, $packageName)) {
                    $commitsTouching[$currentHash] = true;
                }
            }
        }

        return count($commitsTouching) >= 3;
    }
}
