<?php

declare(strict_types=1);

namespace Satiate\Audit\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Satiate\Audit\Auditor;
use Satiate\Audit\AuditResult;

/**
 * A batch of independent audit targets handled by a single worker process. Bundling many targets
 * into one task — instead of one task per target — collapses the per-task channel round-trips to one
 * per batch and reuses a single Auditor across the batch, which is what lets parallelism scale past
 * the point where scheduling overhead dominates (see ADR-0005). Targets remain independent (each
 * archive extracts into its own temp dir), so the findings map serialises back unchanged.
 *
 * @implements Task<array<string, list<AuditResult>>, never, never>
 */
final readonly class BatchAuditTask implements Task
{
    /**
     * @param array<string, AuditTargetKind> $targets path => kind
     */
    public function __construct(
        private array $targets,
    ) {}

    /**
     * @return array<string, list<AuditResult>>
     */
    public function run(Channel $channel, Cancellation $cancellation): array
    {
        $auditor = new Auditor();
        $results = [];

        foreach ($this->targets as $path => $kind) {
            $results[$path] = match ($kind) {
                AuditTargetKind::Php => $auditor->auditFile('', '', $path),
                AuditTargetKind::ComposerJson => $auditor->auditComposerJson('', '', $path),
                AuditTargetKind::Archive => $auditor->auditArchive('', '', $path),
            };
        }

        return $results;
    }
}
