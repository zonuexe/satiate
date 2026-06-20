<?php

declare(strict_types=1);

namespace Satiate\Tests\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Audit\AuditResult;
use Satiate\Audit\AuditSummary;
use Satiate\Audit\Severity;

#[CoversClass(AuditSummary::class)]
final class AuditSummaryTest extends TestCase
{
    public function testEmptySummaryHasZeroCounts(): void
    {
        $summary = new AuditSummary();

        self::assertSame(0, $summary->total());
        self::assertSame(0, $summary->count(Severity::Critical));
        self::assertSame(0, $summary->count(Severity::Warning));
        self::assertSame(0, $summary->count(Severity::Info));
        self::assertSame(0, $summary->countAtOrAbove(Severity::Info));
    }

    public function testAddIncrementsTheMatchingSeverity(): void
    {
        $summary = new AuditSummary();
        $summary->add($this->finding(Severity::Critical));
        $summary->add($this->finding(Severity::Critical));
        $summary->add($this->finding(Severity::Warning));

        self::assertSame(2, $summary->count(Severity::Critical));
        self::assertSame(1, $summary->count(Severity::Warning));
        self::assertSame(0, $summary->count(Severity::Info));
        self::assertSame(3, $summary->total());
    }

    public function testAddAllAccumulatesEveryResult(): void
    {
        $summary = new AuditSummary();
        $summary->addAll([
            $this->finding(Severity::Info),
            $this->finding(Severity::Info),
            $this->finding(Severity::Warning),
        ]);

        self::assertSame(2, $summary->count(Severity::Info));
        self::assertSame(1, $summary->count(Severity::Warning));
        self::assertSame(3, $summary->total());
    }

    public function testCountAtOrAboveRespectsSeverityOrder(): void
    {
        $summary = new AuditSummary();
        $summary->addAll([
            $this->finding(Severity::Critical),
            $this->finding(Severity::Critical),
            $this->finding(Severity::Warning),
            $this->finding(Severity::Warning),
            $this->finding(Severity::Warning),
            $this->finding(Severity::Info),
        ]);

        // 2 critical + 3 warning + 1 info
        self::assertSame(6, $summary->countAtOrAbove(Severity::Info));
        self::assertSame(5, $summary->countAtOrAbove(Severity::Warning));
        self::assertSame(2, $summary->countAtOrAbove(Severity::Critical));
    }

    private function finding(Severity $severity): AuditResult
    {
        return new AuditResult(
            package: 'vendor/pkg',
            version: '1.0.0',
            file: 'src/File.php',
            line: 1,
            pattern: 'test',
            description: 'test finding',
            severity: $severity,
        );
    }
}
