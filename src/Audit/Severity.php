<?php

declare(strict_types=1);

namespace Satiate\Audit;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
