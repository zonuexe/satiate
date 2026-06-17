<?php

declare(strict_types=1);

namespace Satiate\Config;

use Psl\Type;

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

        $repositoryType = Type\shape([
            'type' => Type\string(),
            'url' => Type\string(),
            'name' => Type\optional(Type\string()),
        ]);

        $repositories = [];

        if (isset($decoded['repositories']) && is_array($decoded['repositories'])) {
            foreach ($decoded['repositories'] as $repo) {
                if (is_array($repo) && isset($repo['type'], $repo['url']) && is_string($repo['type']) && is_string($repo['url'])) {
                    $repositories[] = $repositoryType->coerce($repo);
                }
            }
        }

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

        $maxVersionsPerPackage = isset($decoded['max-versions-per-package']) && is_int($decoded['max-versions-per-package']) ? $decoded['max-versions-per-package'] : 0;

        $archiveType = Type\shape([
            'directory' => Type\string(),
            'format' => Type\string(),
            'prefix-url' => Type\optional(Type\string()),
            'skip-dev' => Type\optional(Type\bool()),
        ]);

        $archive = null;

        if (
            isset($decoded['archive'])
            && is_array($decoded['archive'])
            && isset($decoded['archive']['directory'], $decoded['archive']['format'])
        ) {
            $archive = $archiveType->coerce($decoded['archive']);
        }

        return new SatisConfig(
            name: $name,
            homepage: $homepage,
            repositories: $repositories,
            require: $require,
            requireAll: $requireAll,
            requireDependencies: $requireDependencies,
            requireDevDependencies: $requireDevDependencies,
            archive: $archive,
            maxVersionsPerPackage: $maxVersionsPerPackage,
        );
    }
}
