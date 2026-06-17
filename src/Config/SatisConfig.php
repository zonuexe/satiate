<?php

declare(strict_types=1);

namespace Satiate\Config;

readonly class SatisConfig
{
    /**
     * @param array<int, array{type: string, url: string, name?: string}> $repositories
     * @param array<string, string> $require
     * @param ?array{directory: string, format: string, prefix-url?: string, skip-dev?: bool} $archive
     */
    public function __construct(
        public string $name,
        public string $homepage,
        public array $repositories = [],
        public array $require = [],
        public bool $requireAll = false,
        public bool $requireDependencies = true,
        public bool $requireDevDependencies = false,
        public ?array $archive = null,
    ) {}
}
