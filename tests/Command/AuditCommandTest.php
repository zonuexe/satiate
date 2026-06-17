<?php

declare(strict_types=1);

namespace Satiate\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Command\AuditCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AuditCommand::class)]
final class AuditCommandTest extends TestCase
{
    public function testCommandName(): void
    {
        $command = new AuditCommand();
        self::assertSame('audit', $command->getName());
    }

    public function testExecuteWithoutPathShowsError(): void
    {
        $command = new AuditCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--path is required', $tester->getDisplay());
    }

    public function testExecuteWithNonexistentPathShowsError(): void
    {
        $command = new AuditCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--path' => '/nonexistent',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testExecuteWithCleanDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/safe.php', '<?php echo "hello";');

        $command = new AuditCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--path' => $tmpDir,
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No suspicious patterns', $tester->getDisplay());
    }

    public function testExecuteWithSuspiciousCode(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/eval.php', '<?php eval($x);');

        $command = new AuditCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--path' => $tmpDir,
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('issue(s) found', $tester->getDisplay());
        self::assertStringContainsString('eval', $tester->getDisplay());
    }

    private function cleanupDir(string $path): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
