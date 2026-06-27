<?php

declare(strict_types=1);

namespace Satiate\Audit\Parallel;

use Satiate\Audit\Auditor;
use Satiate\Audit\AuditResult;

/**
 * Audits a set of independent targets and returns each target's findings keyed by its path. The
 * single entry point both `audit` and `build` use: with `--jobs 1` it runs the targets inline; with
 * more it hands them to the worker pool. Either way the caller aggregates and emits the path-keyed
 * results in its own deterministic order (see ADR-0005).
 */
final readonly class AuditExecutor
{
    public function __construct(
        private int $jobs,
    ) {}

    /**
     * @param list<AuditTarget> $targets
     * @return array<string, list<AuditResult>> findings keyed by each target's path
     */
    public function run(array $targets): array
    {
        if ($this->jobs > 1) {
            return (new ParallelAuditRunner($this->jobs))->run($targets);
        }

        $auditor = new Auditor();
        $results = [];

        foreach ($targets as $target) {
            $results[$target->path] = $target->auditWith($auditor);
        }

        return $results;
    }
}
