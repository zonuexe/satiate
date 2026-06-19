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

    public function testConfigureRegistersDescriptionAndOptions(): void
    {
        $command = new AuditCommand();
        self::assertSame('Audit packages for suspicious code patterns', $command->getDescription());

        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('config'));
        self::assertSame('c', $definition->getOption('config')->getShortcut());
        self::assertTrue($definition->getOption('config')->isValueRequired());
        self::assertSame('satis.json', $definition->getOption('config')->getDefault());

        self::assertTrue($definition->hasOption('path'));
        self::assertTrue($definition->getOption('path')->isValueRequired());

        self::assertTrue($definition->hasOption('cache-path'));
        self::assertTrue($definition->getOption('cache-path')->isValueRequired());
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
        self::assertStringContainsString('/nonexistent', $tester->getDisplay());
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
        // Clean run must NOT print issue count
        self::assertStringNotContainsString('issue(s) found', $tester->getDisplay());
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

    /**
     * Kills: MatchArmRemoval (Severity::Critical → 'error' tag).
     * eval() is Critical; the match arm maps Critical → 'error', so the output
     * line bracket contains "critical" (Symfony strips the XML tag but keeps the value).
     * Also asserts the tag is NOT "comment" or "info" to catch arm-swap mutants.
     */
    public function testCriticalSeverityUsesErrorTag(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/evil.php', '<?php eval($x);');

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);

        // The sprintf format is '[<%s>%s</%s>]' where $tag comes from the match arm.
        // Symfony CommandTester without decoration strips tags: '[critical]' remains.
        self::assertStringContainsString('[critical]', $display);
        // Ensure the wrong tags are not used
        self::assertStringNotContainsString('[comment]', $display);
        self::assertStringNotContainsString('[warning]', $display);
    }

    /**
     * Kills: MatchArmRemoval (Severity::Warning → 'comment' tag).
     * exec() triggers a Warning result; the match arm maps Warning → 'comment'.
     */
    public function testWarningSeverityUsesCommentTag(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/warn.php', '<?php exec($cmd);');

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);

        // Symfony strips XML; the bracket text is the severity value: 'warning'
        self::assertStringContainsString('[warning]', $display);
        self::assertStringNotContainsString('[critical]', $display);
        self::assertStringNotContainsString('[error]', $display);
    }

    /**
     * Kills: MatchArmRemoval (Severity::Info → 'info' tag).
     * assert() triggers an Info result; the match arm maps Info → 'info'.
     */
    public function testInfoSeverityUsesInfoTag(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/info.php', '<?php assert($x === 1);');

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);

        // The bracket shows the severity value string 'info' (Severity::Info case value)
        self::assertStringContainsString('[info]', $display);
        self::assertStringNotContainsString('[critical]', $display);
        self::assertStringNotContainsString('[warning]', $display);
    }

    /**
     * Kills: Increment mutant (totalResults-- vs totalResults++).
     * Two suspicious constructs → EXACTLY "2 issue(s) found", not "-2 issue(s) found".
     */
    public function testTotalResultsCountIsCorrect(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        // Two eval() calls → two Critical results
        file_put_contents($tmpDir . '/two.php', '<?php eval($a); eval($b);');

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);

        // Must be exactly "2 issue(s) found" — "−2 issue(s) found" must NOT match
        self::assertMatchesRegularExpression('/\b2 issue\(s\) found/', $display);
        self::assertDoesNotMatchRegularExpression('/-\d+ issue\(s\) found/', $display);
    }

    /**
     * Kills: ArrayOneItem mutant — phpFilesIn must return ALL PHP files, not just 1.
     * Also kills: sort FunctionCallRemoval — files must appear in sorted order.
     */
    public function testMultiplePhpFilesAreAllAuditedInSortedOrder(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        // Files named so alphabetical order is predictable: aaa.php < bbb.php < ccc.php
        file_put_contents($tmpDir . '/aaa.php', '<?php eval($x);');
        file_put_contents($tmpDir . '/bbb.php', '<?php eval($y);');
        file_put_contents($tmpDir . '/ccc.php', '<?php eval($z);');

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);

        // All three files must appear
        self::assertStringContainsString('aaa.php', $display);
        self::assertStringContainsString('bbb.php', $display);
        self::assertStringContainsString('ccc.php', $display);

        // Sorted order: aaa before bbb before ccc
        self::assertLessThan(
            strpos($display, 'bbb.php'),
            strpos($display, 'aaa.php'),
            'aaa.php should appear before bbb.php in sorted output',
        );
        self::assertLessThan(
            strpos($display, 'ccc.php'),
            strpos($display, 'bbb.php'),
            'bbb.php should appear before ccc.php in sorted output',
        );

        // All three issues counted
        self::assertStringContainsString('3 issue(s) found', $display);
    }

    /**
     * Kills: LogicalAnd mutant in phpFilesIn ($file->isFile() || vs &&).
     * Non-PHP files must not be audited / counted.
     */
    public function testNonPhpFilesAreIgnored(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        // A text file with "eval" in it — should be ignored
        file_put_contents($tmpDir . '/notes.txt', 'eval is evil');
        // A clean PHP file
        file_put_contents($tmpDir . '/clean.php', '<?php echo "ok";');

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);

        self::assertStringContainsString('No suspicious patterns', $display);
        self::assertStringNotContainsString('issue(s) found', $display);
    }

    /**
     * Kills: Empty-directory path → "No PHP files found" message.
     * Also kills: ReturnRemoval after the "No PHP files found" writeln (mutant 2).
     * Without the return, execution falls through and also prints "No suspicious
     * patterns detected." — assert that second message does NOT appear.
     */
    public function testExecuteWithEmptyDirectoryShowsNoFilesMessage(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);

        $command = new AuditCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--path' => $tmpDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No PHP files found', $display);
        // Must NOT fall through to the "no patterns" message
        self::assertStringNotContainsString('No suspicious patterns detected', $display);
    }

    /**
     * Kills line-49 cache-path condition mutants (NotIdentical, LogicalAnd, Concat,
     * ConcatOperandRemoval variants) and line-104 cache-write mutants.
     *
     * Exercises: existing audited-files.json is loaded → file already in cache with
     * current mtime is SKIPPED (cache-hit), the cache file is written with merged data.
     */
    public function testCachePathLoadsExistingAuditedFilesAndSkipsCachedFiles(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        $cacheDir = sys_get_temp_dir() . '/audit_cache_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        mkdir($cacheDir);

        $phpFile = $tmpDir . '/eval.php';
        file_put_contents($phpFile, '<?php eval($x);');

        // Pre-populate the cache with the file's current mtime so it should be skipped
        $mtime = filemtime($phpFile);
        self::assertNotFalse($mtime);

        $cacheFile = $cacheDir . '/audited-files.json';
        file_put_contents($cacheFile, json_encode([
            $phpFile => $mtime,
        ], JSON_PRETTY_PRINT));

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
            '--cache-path' => $cacheDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);
        $this->cleanupDir($cacheDir);

        // File was cached → no results printed → clean output
        self::assertStringContainsString('No suspicious patterns', $display);
        self::assertStringNotContainsString('issue(s) found', $display);
    }

    /**
     * Kills: line-75 Identical mutant ($auditedFiles[$file] !== $mtime skips nothing).
     *
     * An already-cached file with the SAME mtime must be skipped.
     * A file with a DIFFERENT (stale) mtime entry must be re-audited.
     */
    public function testCacheMtimeSkipOnlyWhenMtimeMatches(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        $cacheDir = sys_get_temp_dir() . '/audit_cache_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        mkdir($cacheDir);

        $phpFile = $tmpDir . '/eval.php';
        file_put_contents($phpFile, '<?php eval($x);');

        // Use an intentionally WRONG mtime so the cache entry is stale → file re-audited
        $cacheFile = $cacheDir . '/audited-files.json';
        file_put_contents($cacheFile, json_encode([
            $phpFile => 1,
        ], JSON_PRETTY_PRINT));

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
            '--cache-path' => $cacheDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);
        $this->cleanupDir($cacheDir);

        // Stale cache entry → file is re-audited → issues found
        self::assertStringContainsString('issue(s) found', $display);
    }

    /**
     * Kills: line-104 NotIdentical / LogicalAnd cache-write condition mutants.
     *
     * After auditing new files, the cache file must be written / updated.
     */
    public function testCachePathWritesAuditedFilesJson(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        $cacheDir = sys_get_temp_dir() . '/audit_cache_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        // cacheDir does NOT exist yet — command must create it

        $phpFile = $tmpDir . '/clean.php';
        file_put_contents($phpFile, '<?php echo "ok";');

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
            '--cache-path' => $cacheDir,
        ]);

        $cacheFile = $cacheDir . '/audited-files.json';
        $exists = is_file($cacheFile);

        if (is_dir($cacheDir)) {
            $this->cleanupDir($cacheDir);
        }

        $this->cleanupDir($tmpDir);

        self::assertTrue($exists, 'audited-files.json should have been created in the cache directory');
    }

    /**
     * Kills: line-104 NotIdentical ($newlyAudited === []).
     *
     * The cache must contain the file that was just audited.
     */
    public function testCacheFileContainsAuditedFileEntry(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        $cacheDir = sys_get_temp_dir() . '/audit_cache_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);

        $phpFile = $tmpDir . '/clean.php';
        file_put_contents($phpFile, '<?php echo "ok";');

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
            '--cache-path' => $cacheDir,
        ]);

        // Capture mtime before cleanup removes the file
        $expectedMtime = filemtime($phpFile);
        self::assertNotFalse($expectedMtime, 'filemtime must succeed while phpFile still exists');

        $cacheFile = $cacheDir . '/audited-files.json';
        $cacheContent = is_file($cacheFile) ? file_get_contents($cacheFile) : false;
        $decoded = $cacheContent !== false ? json_decode($cacheContent, true) : null;

        if (is_dir($cacheDir)) {
            $this->cleanupDir($cacheDir);
        }

        $this->cleanupDir($tmpDir);

        self::assertIsArray($decoded, 'Cache JSON must decode to an array');
        self::assertArrayHasKey($phpFile, $decoded, 'Cache must contain an entry for the audited file');
        self::assertSame($expectedMtime, $decoded[$phpFile], 'Cache entry must store the file mtime');
    }

    /**
     * Kills: Continue_ → break mutant (line 76).
     *
     * Two files: first is in cache (matching mtime → should be skipped),
     * second is NOT in cache → must still be audited.
     * With break instead of continue the loop stops after the first (cached) file,
     * so the second (suspicious) file is never audited → no issues found.
     */
    public function testCachedFileIsSkippedButOtherFilesAreStillAudited(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        $cacheDir = sys_get_temp_dir() . '/audit_cache_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        mkdir($cacheDir);

        // aaa.php — clean, will be pre-populated in cache with correct mtime
        $cleanFile = $tmpDir . '/aaa.php';
        file_put_contents($cleanFile, '<?php echo "ok";');
        $mtime = filemtime($cleanFile);
        self::assertNotFalse($mtime);

        // bbb.php — suspicious, NOT in cache → must be audited
        $suspFile = $tmpDir . '/bbb.php';
        file_put_contents($suspFile, '<?php eval($x);');

        // Cache only the first file (aaa.php)
        file_put_contents(
            $cacheDir . '/audited-files.json',
            json_encode([
                $cleanFile => $mtime,
            ], JSON_PRETTY_PRINT),
        );

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
            '--cache-path' => $cacheDir,
        ]);

        $display = $tester->getDisplay();
        $this->cleanupDir($tmpDir);
        $this->cleanupDir($cacheDir);

        // bbb.php must have been audited despite aaa.php being skipped
        self::assertStringContainsString('issue(s) found', $display);
        self::assertStringContainsString('bbb.php', $display);
    }

    /**
     * Kills: UnwrapArrayMerge mutant (line 109) — $merged = $newlyAudited instead of merge.
     *
     * Pre-populate cache with an existing (already-audited) entry. After running with a
     * NEW file, the written cache must contain BOTH the old entry AND the new entry.
     */
    public function testCacheMergesExistingEntriesWithNewlyAuditedFiles(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        $cacheDir = sys_get_temp_dir() . '/audit_cache_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        mkdir($cacheDir);

        // A file that is already cached (will be skipped during the run)
        $oldFile = '/some/previously/audited/file.php';
        $oldMtime = 1700000000; // arbitrary timestamp

        // A new clean PHP file to audit
        $newFile = $tmpDir . '/new.php';
        file_put_contents($newFile, '<?php echo "hello";');

        // Pre-populate cache with the old entry
        file_put_contents(
            $cacheDir . '/audited-files.json',
            json_encode([
                $oldFile => $oldMtime,
            ], JSON_PRETTY_PRINT),
        );

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
            '--cache-path' => $cacheDir,
        ]);

        $cacheFile = $cacheDir . '/audited-files.json';
        $cacheContent = is_file($cacheFile) ? file_get_contents($cacheFile) : false;
        $decoded = $cacheContent !== false ? json_decode($cacheContent, true) : null;

        $this->cleanupDir($tmpDir);
        $this->cleanupDir($cacheDir);

        self::assertIsArray($decoded, 'Cache must decode to an array');
        // Old entry must be preserved (array_merge behavior)
        self::assertArrayHasKey($oldFile, $decoded, 'Old cache entry must be preserved after merge');
        self::assertSame($oldMtime, $decoded[$oldFile], 'Old cache mtime must be unchanged');
        // New entry must also be present
        self::assertArrayHasKey($newFile, $decoded, 'Newly audited file must appear in merged cache');
    }

    /**
     * Kills: DecrementInteger / IncrementInteger on mkdir permission 0755 (line 106).
     *
     * When the cache directory does not yet exist the command creates it with 0755.
     * Mutants change the constant to 0o754 (decimal 492) or 0o756 (decimal 494).
     * With the standard umask 022: 0755 & ~022 = 0755; 0754 & ~022 = 0754;
     * 0756 & ~022 = 0754. So both mutants produce 0754, not 0755.
     *
     * This test is skipped on systems with a non-standard umask to avoid fragility.
     */
    public function testCacheDirectoryIsCreatedWithCorrectPermissions(): void
    {
        if (umask() !== 0022) {
            self::markTestSkipped('Skipped: umask is not 0022; permission assertion would be unreliable.');
        }

        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        $cacheDir = sys_get_temp_dir() . '/audit_cache_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        // cacheDir must NOT exist so the command creates it
        self::assertDirectoryDoesNotExist($cacheDir);

        $phpFile = $tmpDir . '/clean.php';
        file_put_contents($phpFile, '<?php echo "ok";');

        $command = new AuditCommand();
        $tester = new CommandTester($command);
        $tester->execute([
            '--path' => $tmpDir,
            '--cache-path' => $cacheDir,
        ]);

        $actualPerms = is_dir($cacheDir) ? (fileperms($cacheDir) & 0777) : null;

        if (is_dir($cacheDir)) {
            $this->cleanupDir($cacheDir);
        }

        $this->cleanupDir($tmpDir);

        self::assertNotNull($actualPerms, 'Cache directory must have been created');
        self::assertSame(0755, $actualPerms, sprintf(
            'Cache directory must be created with 0755 permissions; got 0%o',
            $actualPerms,
        ));
    }

    /**
     * Verifies that passing an empty string as --cache-path is treated the same as
     * no cache (kills: NotIdentical $cachePath === '' mutant on line 49 and 104).
     */
    public function testEmptyCachePathIsIgnored(): void
    {
        $tmpDir = sys_get_temp_dir() . '/audit_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/clean.php', '<?php echo "ok";');

        $command = new AuditCommand();
        $tester = new CommandTester($command);

        // Should not crash, should not write any cache file
        $exitCode = $tester->execute([
            '--path' => $tmpDir,
            '--cache-path' => '',
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('is required', $tester->getDisplay());
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
