<?php

declare(strict_types=1);

namespace Satiate\Audit\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Satiate\Audit\Auditor;
use Satiate\Audit\AuditResult;

/**
 * One independent audit unit run inside a worker process: a single PHP file, a composer.json, or a
 * distribution archive. Each target is self-contained (an archive is extracted into its own temp
 * dir), so tasks share no state and the findings serialise back to the parent unchanged — the same
 * `list<AuditResult>` the sequential path produces.
 *
 * @implements Task<list<AuditResult>, never, never>
 */
final readonly class AuditTask implements Task
{
    public function __construct(
        private string $package,
        private string $version,
        private string $targetPath,
        private AuditTargetKind $kind,
    ) {}

    /**
     * @return list<AuditResult>
     */
    public function run(Channel $channel, Cancellation $cancellation): array
    {
        $auditor = new Auditor();

        return match ($this->kind) {
            AuditTargetKind::Php => $auditor->auditFile($this->package, $this->version, $this->targetPath),
            AuditTargetKind::ComposerJson => $auditor->auditComposerJson($this->package, $this->version, $this->targetPath),
            AuditTargetKind::Archive => $auditor->auditArchive($this->package, $this->version, $this->targetPath),
        };
    }
}
