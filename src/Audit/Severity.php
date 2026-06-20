<?php

declare(strict_types=1);

namespace Satiate\Audit;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * Ordinal for comparing severities: Info (0) < Warning (1) < Critical (2).
     *
     * Used to filter findings against a minimum-severity threshold.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }
}
