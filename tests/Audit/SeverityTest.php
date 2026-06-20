<?php

declare(strict_types=1);

namespace Satiate\Tests\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Audit\Severity;

#[CoversClass(Severity::class)]
final class SeverityTest extends TestCase
{
    public function testRankOrdersInfoBelowWarningBelowCritical(): void
    {
        self::assertSame(0, Severity::Info->rank());
        self::assertSame(1, Severity::Warning->rank());
        self::assertSame(2, Severity::Critical->rank());
    }

    public function testRankIsStrictlyMonotonic(): void
    {
        self::assertLessThan(Severity::Warning->rank(), Severity::Info->rank());
        self::assertLessThan(Severity::Critical->rank(), Severity::Warning->rank());
    }
}
