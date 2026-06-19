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

        try {
            $analyzer = new LockAnalyzer();
            $analyzer->analyze($lockFile, \dirname($lockFile));
            self::fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Invalid', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());
        } finally {
            if (is_file($lockFile)) {
                unlink($lockFile);
            }
        }
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

    public function testVersionConstraintPrefix(): void
    {
        $lockJson = json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => 'v3.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $lockFile = tempnam(sys_get_temp_dir(), 'lock_test_');
        file_put_contents($lockFile, $lockJson);

        $analyzer = new LockAnalyzer();
        $constraints = $analyzer->analyze($lockFile, \dirname($lockFile));

        unlink($lockFile);

        self::assertSame('>=3.0.0', $constraints[0]->constraint);
    }

    /**
     * When the project directory does not contain a composer.lock, assessReversionRisk
     * must return false (not true). Kills the FalseValue mutant on the early-return.
     */
    public function testIsRiskyFalseWhenNoComposerLockInProjectDir(): void
    {
        // Create a temp dir WITHOUT a composer.lock inside it.
        $projectDir = sys_get_temp_dir() . '/locktest_norepo_' . uniqid('', true);
        mkdir($projectDir, 0777, true);

        $lockJson = json_encode([
            'packages' => [
                [
                    'name' => 'acme/some-package',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $lockFile = tempnam(sys_get_temp_dir(), 'lock_test_');
        file_put_contents($lockFile, $lockJson);

        try {
            $analyzer = new LockAnalyzer();
            $constraints = $analyzer->analyze($lockFile, $projectDir);

            self::assertCount(1, $constraints);
            self::assertFalse(
                $constraints[0]->isRisky,
                'isRisky must be false when no composer.lock exists in the project directory',
            );
        } finally {
            unlink($lockFile);
            rmdir($projectDir);
        }
    }

    /**
     * When git blame shows >= 3 distinct commits touching a package name, isRisky must be true.
     * This kills the LogicalNot mutant (which returns false early when the lock file IS found)
     * and the ConcatOperandRemoval / Concat mutants (which construct the wrong path).
     */
    public function testIsRiskyTrueWhenGitBlameShowsThreeOrMoreCommits(): void
    {
        $projectDir = sys_get_temp_dir() . '/locktest_gitrepo_' . uniqid('', true);
        mkdir($projectDir, 0777, true);

        // Bootstrap a minimal git repository so shell_exec git blame returns real output.
        $gitInit = [];
        exec('git -C ' . escapeshellarg($projectDir) . ' init 2>&1', $gitInit, $gitInitCode);
        if ($gitInitCode !== 0) {
            self::markTestSkipped('git is not available: ' . implode("\n", $gitInit));
        }

        exec('git -C ' . escapeshellarg($projectDir) . ' config user.email "test@example.com"');
        exec('git -C ' . escapeshellarg($projectDir) . ' config user.name "Test"');

        // Each commit modifies a DIFFERENT line so git blame shows a different hash per line.
        // Three lines, each last-touched by a different commit, all containing "acme/risky".
        $packageName = 'acme/risky';

        // Commit 1: add line 1 containing the package name.
        file_put_contents($projectDir . '/composer.lock', $packageName . "-line1\nplaceholder2\nplaceholder3\n");
        exec('git -C ' . escapeshellarg($projectDir) . ' add composer.lock');
        exec('git -C ' . escapeshellarg($projectDir) . ' commit -m "commit1" 2>&1');

        // Commit 2: replace line 2.
        file_put_contents($projectDir . '/composer.lock', $packageName . "-line1\n" . $packageName . "-line2\nplaceholder3\n");
        exec('git -C ' . escapeshellarg($projectDir) . ' add composer.lock');
        exec('git -C ' . escapeshellarg($projectDir) . ' commit -m "commit2" 2>&1');

        // Commit 3: replace line 3.
        file_put_contents($projectDir . '/composer.lock', $packageName . "-line1\n" . $packageName . "-line2\n" . $packageName . "-line3\n");
        exec('git -C ' . escapeshellarg($projectDir) . ' add composer.lock');
        exec('git -C ' . escapeshellarg($projectDir) . ' commit -m "commit3" 2>&1');

        // The analysis lock file is a valid JSON lock with the risky package.
        $lockJson = json_encode([
            'packages' => [
                [
                    'name' => $packageName,
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $lockFile = tempnam(sys_get_temp_dir(), 'lock_test_');
        file_put_contents($lockFile, $lockJson);

        try {
            $analyzer = new LockAnalyzer();
            $constraints = $analyzer->analyze($lockFile, $projectDir);

            self::assertCount(1, $constraints);
            self::assertTrue(
                $constraints[0]->isRisky,
                'isRisky must be true when >= 3 distinct commits touch the package name in git blame',
            );
        } finally {
            unlink($lockFile);
            // Remove projectDir recursively.
            $this->removeDir($projectDir);
        }
    }

    public function testSecondPackageConstraintIsSet(): void
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

        self::assertSame('>=2.1.0', $constraints[1]->constraint);
        self::assertFalse($constraints[1]->isRisky);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
