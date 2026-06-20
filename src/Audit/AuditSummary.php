<?php

declare(strict_types=1);

namespace Satiate\Audit;

/**
 * Mutable accumulator of audit findings grouped by severity.
 *
 * Shared by the standalone `audit` command and the build-time audit so the per-severity
 * breakdown and the "fail at or above" gate are computed in exactly one place.
 */
final class AuditSummary
{
    /**
     * @var array<string, int> severity value => number of findings
     */
    private array $counts;

    public function __construct()
    {
        $this->counts = [
            Severity::Critical->value => 0,
            Severity::Warning->value => 0,
            Severity::Info->value => 0,
        ];
    }

    public function add(AuditResult $result): void
    {
        $this->counts[$result->severity->value]++;
    }

    /**
     * @param iterable<AuditResult> $results
     */
    public function addAll(iterable $results): void
    {
        foreach ($results as $result) {
            $this->add($result);
        }
    }

    public function count(Severity $severity): int
    {
        return $this->counts[$severity->value];
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * Number of findings whose severity is at or above the given threshold.
     */
    public function countAtOrAbove(Severity $threshold): int
    {
        $total = 0;

        foreach (Severity::cases() as $severity) {
            if ($severity->rank() >= $threshold->rank()) {
                $total += $this->counts[$severity->value];
            }
        }

        return $total;
    }
}
