<?php

declare(strict_types=1);

namespace Satiate\Audit\Parallel;

use Satiate\Audit\Auditor;
use Satiate\Audit\AuditResult;

/**
 * One independent audit unit: a path plus how to audit it. The optional package/version are carried
 * through to the findings — the standalone `audit` command leaves them empty, while `build` tags each
 * archive with its package so capability fingerprints can be keyed by name and version.
 *
 * Plain readonly scalars and an enum, so an instance serialises unchanged across a worker boundary.
 */
final readonly class AuditTarget
{
    public function __construct(
        public string $path,
        public AuditTargetKind $kind,
        public string $package = '',
        public string $version = '',
    ) {}

    /**
     * @return list<AuditResult>
     */
    public function auditWith(Auditor $auditor): array
    {
        return match ($this->kind) {
            AuditTargetKind::Php => $auditor->auditFile($this->package, $this->version, $this->path),
            AuditTargetKind::ComposerJson => $auditor->auditComposerJson($this->package, $this->version, $this->path),
            AuditTargetKind::Archive => $auditor->auditArchive($this->package, $this->version, $this->path),
        };
    }
}
