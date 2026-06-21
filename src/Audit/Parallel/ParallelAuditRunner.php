<?php

declare(strict_types=1);

namespace Satiate\Audit\Parallel;

use Amp\Future;
use Amp\Parallel\Worker\ContextWorkerPool;
use Satiate\Audit\AuditResult;

/**
 * Audits independent targets across a pool of worker processes and returns each target's findings
 * keyed by its path. Only the CPU-bound parsing is parallel — the caller still aggregates, caches,
 * and emits results sequentially in its own order, so determinism is preserved (see ADR-0005). PHP
 * CLI is Non-Thread-Safe here, so the pool spawns OS processes, not threads.
 *
 * Targets are bundled into one balanced batch per worker rather than one task per target: with the
 * pool reusing a fixed set of workers, fine-grained tasks add a channel round-trip each and gain
 * nothing once the workers are saturated. Balancing each batch by total file size keeps the slowest
 * batch — which sets the finish time — as short as the input allows.
 */
final readonly class ParallelAuditRunner
{
    public function __construct(
        private int $workers,
    ) {}

    /**
     * @param list<AuditTarget> $targets
     * @return array<string, list<AuditResult>> findings keyed by each target's path
     */
    public function run(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $pool = new ContextWorkerPool($this->workers);

        $futures = [];

        foreach ($this->balance($targets, $this->workers) as $index => $batch) {
            $futures[$index] = $pool->submit(new BatchAuditTask($batch))->getFuture();
        }

        $merged = [];

        foreach (Future\await($futures) as $batchResults) {
            foreach ($batchResults as $path => $results) {
                $merged[$path] = $results;
            }
        }

        // Stop the workers gracefully now the results are in; otherwise the pool's destructor
        // SIGKILLs them, which is noisy and leaves no chance to flush.
        $pool->shutdown();

        return $merged;
    }

    /**
     * Distribute targets into balanced batches with a longest-processing-time-first greedy pass:
     * heaviest targets (by file size) placed first, each onto the currently-lightest batch.
     *
     * More batches than workers (oversubscription) are produced on purpose: with exactly one batch
     * per worker a single heavy batch stalls a worker while others idle, so a few small workloads
     * (low --jobs) lose to per-target dynamic scheduling. Handing the pool ~$oversubscribe× as many
     * batches lets it pull a fresh one whenever a worker frees up — keeping every worker busy to the
     * end — while still collapsing hundreds of targets to a few dozen channel round-trips.
     *
     * @param list<AuditTarget> $targets
     * @return list<list<AuditTarget>>
     */
    private function balance(array $targets, int $workers): array
    {
        $oversubscribe = 3;
        $batchCount = max(1, min($workers * $oversubscribe, \count($targets)));

        // Stat each target once up front; paths are unique, so a path-keyed map is enough.
        $sizes = [];

        foreach ($targets as $target) {
            $size = filesize($target->path);
            $sizes[$target->path] = $size === false ? 0 : $size;
        }

        // Heaviest targets first (LPT): place the big archives before the long tail of small ones.
        usort($targets, static fn(AuditTarget $a, AuditTarget $b): int => $sizes[$b->path] <=> $sizes[$a->path]);

        $batches = array_fill(0, $batchCount, []);
        $loads = array_fill(0, $batchCount, 0);

        foreach ($targets as $target) {
            $lightest = 0;

            foreach ($loads as $index => $load) {
                if ($load < $loads[$lightest]) {
                    $lightest = $index;
                }
            }

            $batches[$lightest][] = $target;
            $loads[$lightest] += $sizes[$target->path];
        }

        return array_values(array_filter($batches, static fn(array $batch): bool => $batch !== []));
    }
}
