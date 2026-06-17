<?php

declare(strict_types=1);

namespace Satiate\Audit;

readonly class AuditResult
{
    public function __construct(
        public string $package,
        public string $version,
        public string $file,
        public int $line,
        public string $pattern,
        public string $description,
        public Severity $severity = Severity::Warning,
    ) {}
}
