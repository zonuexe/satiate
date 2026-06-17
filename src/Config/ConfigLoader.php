<?php

declare(strict_types=1);

namespace Satiate\Config;

final class ConfigLoader
{
    public static function load(string $path): SatisConfig
    {
        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath)) {
            throw new \RuntimeException(\sprintf('Configuration file not found: %s', $path));
        }

        $contents = file_get_contents($realPath);

        if ($contents === false) {
            throw new \RuntimeException(\sprintf('Failed to read configuration file: %s', $path));
        }

        $decoded = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(\sprintf(
                'Failed to parse configuration file: %s — %s',
                $path,
                json_last_error_msg(),
            ));
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException(\sprintf('Configuration file must contain a JSON object: %s', $path));
        }

        $name = isset($decoded['name']) && is_string($decoded['name']) ? $decoded['name'] : '';
        $homepage = isset($decoded['homepage']) && is_string($decoded['homepage']) ? $decoded['homepage'] : '';

        $repositories = [];

        if (isset($decoded['repositories']) && is_array($decoded['repositories'])) {
            foreach ($decoded['repositories'] as $repo) {
                if (is_array($repo) && isset($repo['type'], $repo['url']) && is_string($repo['type']) && is_string($repo['url'])) {
                    $repositories[] = $repo;
                }
            }
        }

        /** @var array<int, array{type: string, url: string, name?: string}> $repositories */

        $require = [];

        if (isset($decoded['require']) && is_array($decoded['require'])) {
            foreach ($decoded['require'] as $key => $constraint) {
                if (is_string($key) && is_string($constraint)) {
                    $require[$key] = $constraint;
                }
            }
        }

        $requireAll = isset($decoded['require-all']) && is_bool($decoded['require-all']) ? $decoded['require-all'] : false;
        $requireDependencies = isset($decoded['require-dependencies']) && is_bool($decoded['require-dependencies']) ? $decoded['require-dependencies'] : true;
        $requireDevDependencies = isset($decoded['require-dev-dependencies']) && is_bool($decoded['require-dev-dependencies']) ? $decoded['require-dev-dependencies'] : false;

        $archive = null;

        if (isset($decoded['archive']) && is_array($decoded['archive'])) {
            $archive = [];

            if (isset($decoded['archive']['directory']) && is_string($decoded['archive']['directory'])) {
                $archive['directory'] = $decoded['archive']['directory'];
            }

            if (isset($decoded['archive']['format']) && is_string($decoded['archive']['format'])) {
                $archive['format'] = $decoded['archive']['format'];
            }

            if (isset($decoded['archive']['prefix-url']) && is_string($decoded['archive']['prefix-url'])) {
                $archive['prefix-url'] = $decoded['archive']['prefix-url'];
            }

            if (isset($decoded['archive']['skip-dev']) && is_bool($decoded['archive']['skip-dev'])) {
                $archive['skip-dev'] = $decoded['archive']['skip-dev'];
            }
        }

        /** @var array{directory: string, format: string, prefix-url?: string, skip-dev?: bool}|null $archive */
        return new SatisConfig(
            name: $name,
            homepage: $homepage,
            repositories: $repositories,
            require: $require,
            requireAll: $requireAll,
            requireDependencies: $requireDependencies,
            requireDevDependencies: $requireDevDependencies,
            archive: $archive,
        );
    }
}
