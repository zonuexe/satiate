<?php

declare(strict_types=1);

namespace Satiate\Audit\Parallel;

use Amp\Future;
use Amp\Parallel\Worker\ContextWorkerPool;
use Satiate\Audit\AuditResult;

/**
 * Audits independent targets across a pool of worker processes and returns each target's findings
 * keyed by its path. Only the CPU-bound parsing runs in parallel — the caller still aggregates,
 * caches, and emits results sequentially in its own order, so determinism is preserved (see
 * ADR-0005). PHP CLI is Non-Thread-Safe here, so the pool spawns OS processes, not threads.
 */
final readonly class ParallelAuditRunner
{
    public function __construct(
        private int $workers,
    ) {}

    /**
     * @param array<string, AuditTask> $tasks targets keyed by path
     * @return array<string, list<AuditResult>> findings keyed by the same path
     */
    public function run(array $tasks): array
    {
        $pool = new ContextWorkerPool($this->workers);

        $futures = [];

        foreach ($tasks as $path => $task) {
            $futures[$path] = $pool->submit($task)->getFuture();
        }

        return Future\await($futures);
    }
}
