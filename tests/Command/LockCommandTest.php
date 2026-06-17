<?php

declare(strict_types=1);

namespace Satiate\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Command\LockCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(LockCommand::class)]
final class LockCommandTest extends TestCase
{
    public function testCommandName(): void
    {
        $command = new LockCommand();
        self::assertSame('lock', $command->getName());
    }

    public function testExecuteWithNonExistentLockFile(): void
    {
        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => '/nonexistent/composer.lock',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testExecuteDryRunWithValidLockFile(): void
    {
        $lockData = json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.2.3',
                    'type' => 'library',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $tmpDir = sys_get_temp_dir() . '/lock_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, $lockData);

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--dry-run' => true,
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('vendor/pkg', $tester->getDisplay());
        self::assertStringContainsString('>=1.2.3', $tester->getDisplay());
    }

    private function cleanupDir(string $path): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
