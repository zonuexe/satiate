<?php

declare(strict_types=1);

namespace Satiate\Lock;

readonly class PackageConstraint
{
    public function __construct(
        public string $name,
        public string $version,
        public string $constraint,
        public bool $isRisky = false,
    ) {}
}
