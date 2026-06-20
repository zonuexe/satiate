<?php

declare(strict_types=1);

namespace Satiate\Tests\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Audit\VersionCapabilityDiff;

#[CoversClass(VersionCapabilityDiff::class)]
final class VersionCapabilityDiffTest extends TestCase
{
    public function testEmptyInputReturnsNothing(): void
    {
        self::assertSame([], (new VersionCapabilityDiff())->introduced([]));
    }

    public function testSingleVersionReturnsNothing(): void
    {
        self::assertSame([], (new VersionCapabilityDiff())->introduced([
            '1.0.0' => ['eval'],
        ]));
    }

    public function testIntroducedCapabilityIsReported(): void
    {
        $result = (new VersionCapabilityDiff())->introduced([
            '1.0.0' => [],
            '2.0.0' => ['eval'],
        ]);

        self::assertSame([
            [
                'version' => '2.0.0',
                'previousVersion' => '1.0.0',
                'capability' => 'eval',
            ],
        ], $result);
    }

    public function testUnchangedCapabilityIsNotReported(): void
    {
        self::assertSame([], (new VersionCapabilityDiff())->introduced([
            '1.0.0' => ['eval'],
            '2.0.0' => ['eval'],
        ]));
    }

    public function testRemovedCapabilityIsNotReported(): void
    {
        // Only newly-gained capabilities are a supply-chain signal; a removal is not.
        self::assertSame([], (new VersionCapabilityDiff())->introduced([
            '1.0.0' => ['eval'],
            '2.0.0' => [],
        ]));
    }

    public function testVersionsAreDiffedInSemverOrderNotInputOrder(): void
    {
        // The map is deliberately out of order: 2.0.0's predecessor must be 1.5.0, not the first key.
        $result = (new VersionCapabilityDiff())->introduced([
            '2.0.0' => ['eval', 'command_execution'],
            '1.0.0' => [],
            '1.5.0' => ['eval'],
        ]);

        self::assertSame([
            [
                'version' => '1.5.0',
                'previousVersion' => '1.0.0',
                'capability' => 'eval',
            ],
            [
                'version' => '2.0.0',
                'previousVersion' => '1.5.0',
                'capability' => 'command_execution',
            ],
        ], $result);
    }

    public function testMultipleIntroducedCapabilitiesAreSortedAlphabetically(): void
    {
        $result = (new VersionCapabilityDiff())->introduced([
            '1.0.0' => [],
            '2.0.0' => ['system', 'eval', 'ffi_usage'],
        ]);

        self::assertSame(['eval', 'ffi_usage', 'system'], array_column($result, 'capability'));
    }

    public function testReintroductionAfterRemovalIsReported(): void
    {
        $result = (new VersionCapabilityDiff())->introduced([
            '1.0.0' => ['eval'],
            '1.1.0' => [],
            '1.2.0' => ['eval'],
        ]);

        self::assertSame([
            [
                'version' => '1.2.0',
                'previousVersion' => '1.1.0',
                'capability' => 'eval',
            ],
        ], $result);
    }
}
