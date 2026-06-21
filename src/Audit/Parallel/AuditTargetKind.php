<?php

declare(strict_types=1);

namespace Satiate\Audit\Parallel;

/**
 * Which auditor entry point a target path is dispatched to. A built mirror stores package code as
 * archives under dist/, while a plain source tree is audited file by file (composer.json included).
 */
enum AuditTargetKind
{
    case Php;
    case ComposerJson;
    case Archive;

    public static function forPath(string $path): self
    {
        return match (true) {
            str_ends_with($path, '.php') => self::Php,
            basename($path) === 'composer.json' => self::ComposerJson,
            default => self::Archive,
        };
    }
}
