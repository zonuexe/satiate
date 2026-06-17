<?php

declare(strict_types=1);

namespace Satiate\Tests\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Lock\LockAnalyzer;

#[CoversClass(LockAnalyzer::class)]
final class LockAnalyzerTest extends TestCase
{
    public function testAnalyzeValidLockFile(): void
    {
        $lockJson = json_encode([
            'packages' => [
                [
                    'name' => 'vendor/package-a',
                    'version' => 'v1.2.3',
                    'type' => 'library',
                ],
                [
                    'name' => 'vendor/package-b',
                    'version' => '2.1.0',
                    'type' => 'library',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $lockFile = tempnam(sys_get_temp_dir(), 'lock_test_');
        file_put_contents($lockFile, $lockJson);

        $analyzer = new LockAnalyzer();
        $constraints = $analyzer->analyze($lockFile, \dirname($lockFile));

        unlink($lockFile);

        self::assertCount(2, $constraints);
        self::assertSame('vendor/package-a', $constraints[0]->name);
        self::assertSame('1.2.3', $constraints[0]->version);
        self::assertSame('>=1.2.3', $constraints[0]->constraint);
        self::assertSame('vendor/package-b', $constraints[1]->name);
        self::assertSame('2.1.0', $constraints[1]->version);
    }

    public function testAnalyzeLockFileNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $analyzer = new LockAnalyzer();
        $analyzer->analyze('/nonexistent/composer.lock', '/tmp');
    }

    public function testAnalyzeInvalidJson(): void
    {
        $lockFile = tempnam(sys_get_temp_dir(), 'lock_test_');
        file_put_contents($lockFile, '{invalid json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid');

        $analyzer = new LockAnalyzer();
        $analyzer->analyze($lockFile, \dirname($lockFile));

        unlink($lockFile);
    }

    public function testEmptyPackagesReturnsEmpty(): void
    {
        $lockJson = json_encode([
            'packages' => [],
        ], JSON_THROW_ON_ERROR);

        $lockFile = tempnam(sys_get_temp_dir(), 'lock_test_');
        file_put_contents($lockFile, $lockJson);

        $analyzer = new LockAnalyzer();
        $constraints = $analyzer->analyze($lockFile, \dirname($lockFile));

        unlink($lockFile);

        self::assertCount(0, $constraints);
    }
}
