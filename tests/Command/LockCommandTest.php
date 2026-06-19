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

    public function testConfigureRegistersDescriptionAndOptions(): void
    {
        $command = new LockCommand();
        self::assertSame('Derive minimum version constraints from composer.lock', $command->getDescription());

        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('lock'));
        self::assertTrue($definition->getOption('lock')->isValueRequired());
        self::assertSame('composer.lock', $definition->getOption('lock')->getDefault());

        self::assertTrue($definition->hasOption('dry-run'));
        self::assertFalse($definition->getOption('dry-run')->acceptValue());

        self::assertTrue($definition->hasOption('config'));
        self::assertSame('c', $definition->getOption('config')->getShortcut());
        self::assertTrue($definition->getOption('config')->isValueRequired());
        self::assertSame('satis.json', $definition->getOption('config')->getDefault());
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

    /**
     * Kills mutant #2 (ReturnRemoval on line 39): without the early return, code falls through
     * to the realpath() check on line 44 which also prints "not found" and returns FAILURE.
     * The observable difference is that the error message appears TWICE instead of once.
     */
    public function testExecuteMissingLockFileDoesNotPrintPackages(): void
    {
        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => '/nonexistent/composer.lock',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringNotContainsString('Found', $tester->getDisplay());
        // The error must appear exactly once (without early return it appears twice).
        self::assertSame(1, substr_count($tester->getDisplay(), 'not found'));
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

    /**
     * Kills mutant #3 (MethodCallRemoval on line 60): asserts the "Found N locked packages:"
     * message is actually written.
     */
    public function testExecutePrintsFoundNPackagesMessage(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/one',
                    'version' => '1.0.0',
                ],
                [
                    'name' => 'vendor/two',
                    'version' => '2.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--dry-run' => true,
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Found 2 locked packages:', $tester->getDisplay());
    }

    /**
     * Kills mutant #4 (Ternary on line 65): a risky package must show "[reversion risk]"
     * in the output. Uses a real git repo where the package appears in ≥3 blamed commits.
     */
    public function testExecutePrintsRiskyFlagForRiskyPackage(): void
    {
        $tmpDir = $this->createRiskyGitRepo();
        $lockPath = $tmpDir . '/composer.lock';

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--dry-run' => true,
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[reversion risk]', $tester->getDisplay());
    }

    /**
     * Kills mutant #4 (Ternary on line 65): a non-risky package must NOT show "[reversion risk]".
     */
    public function testExecuteDoesNotPrintRiskyFlagForNonRiskyPackage(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/stable',
                    'version' => '3.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--dry-run' => true,
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('[reversion risk]', $tester->getDisplay());
    }

    /**
     * Kills mutants #8 and #9 (IfNegation / MethodCallRemoval on lines 80-81):
     * the dry-run summary message must appear when --dry-run is passed.
     */
    public function testExecuteDryRunPrintsDryRunMessage(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--dry-run' => true,
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Dry-run mode', $tester->getDisplay());
        self::assertStringContainsString('no files modified', $tester->getDisplay());
    }

    /**
     * Kills mutant #8 (IfNegation on line 80): the dry-run message must NOT appear
     * in non-dry-run mode.
     */
    public function testExecuteNonDryRunDoesNotPrintDryRunMessage(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $command = new LockCommand();
        $tester = new CommandTester($command);

        // No --config provided, so no satis.json to apply to; still tests the dry-run guard.
        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $tmpDir . '/nonexistent-satis.json',
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('Dry-run mode', $tester->getDisplay());
    }

    /**
     * Kills mutants #5, #6, #7 (LogicalNot / LogicalAnd / LogicalAndSingleSubExprNegation on line 76):
     * with dry-run=false and a non-existent config, apply must NOT be called.
     */
    public function testExecuteNonDryRunWithMissingSatisJsonDoesNotApply(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $missingConfig = $tmpDir . '/nonexistent-satis.json';

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $missingConfig,
        ]);

        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        // No "Updated …" message means applyToSatisJson was not called.
        self::assertStringNotContainsString('Updated', $tester->getDisplay());
    }

    /**
     * Kills mutants #1, #5, #6, #7 (Identical / LogicalNot / LogicalAnd on line 34 and 76):
     * with dry-run=false and an existing config file, satis.json must be updated.
     */
    public function testExecuteNonDryRunWithExistingSatisJsonUpdatesConstraints(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '2.3.4',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $satisPath = $tmpDir . '/satis.json';
        file_put_contents($satisPath, json_encode([
            'name' => 'my/satis',
            'require' => [],
        ], JSON_THROW_ON_ERROR));

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $satisPath,
        ]);

        $display = $tester->getDisplay();
        $updatedJson = (string) file_get_contents($satisPath);
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Updated', $display);
        self::assertStringNotContainsString('Dry-run mode', $display);
        // Kills mutant #13 (=== false → !== false): on successful write, the error must NOT appear.
        self::assertStringNotContainsString('Failed to write', $display);

        $satisConfig = json_decode($updatedJson, true);
        self::assertIsArray($satisConfig);
        self::assertArrayHasKey('require', $satisConfig);
        self::assertIsArray($satisConfig['require']);
        self::assertSame('>=2.3.4', $satisConfig['require']['vendor/pkg']);
    }

    /**
     * Kills mutant #1 (Identical on line 34) and mutant #5 (LogicalNot on line 76):
     * with dry-run=true and an existing config file, satis.json must NOT be modified.
     */
    public function testExecuteDryRunWithExistingSatisJsonDoesNotModifyIt(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $satisPath = $tmpDir . '/satis.json';
        $originalContent = json_encode([
            'name' => 'my/satis',
            'require' => [],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($satisPath, $originalContent);

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $satisPath,
            '--dry-run' => true,
        ]);

        $contentAfter = (string) file_get_contents($satisPath);
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        // File must be untouched.
        self::assertSame($originalContent, $contentAfter);
        // And the dry-run notice must be shown.
        self::assertStringContainsString('Dry-run mode', $tester->getDisplay());
        self::assertStringNotContainsString('Updated', $tester->getDisplay());
    }

    /**
     * Kills mutants #2-6 (LogicalNot / LogicalOr variants on line 108):
     * when satis.json has no "require" key, the key must be created and the constraint written.
     */
    public function testExecuteNonDryRunCreatesRequireSectionWhenMissing(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $satisPath = $tmpDir . '/satis.json';
        // satis.json exists but has NO "require" key at all.
        file_put_contents($satisPath, json_encode([
            'name' => 'my/satis',
        ], JSON_THROW_ON_ERROR));

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $satisPath,
        ]);

        $updatedJson = (string) file_get_contents($satisPath);
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        $satisConfig = json_decode($updatedJson, true);
        self::assertIsArray($satisConfig);
        self::assertArrayHasKey('require', $satisConfig);
        self::assertIsArray($satisConfig['require']);
        self::assertSame('>=1.0.0', $satisConfig['require']['vendor/pkg']);
    }

    /**
     * Kills mutants #2-6 (LogicalNot / LogicalOr variants on line 108):
     * when satis.json has a "require" key that is NOT an array, it must be reset to [].
     */
    public function testExecuteNonDryRunResetsRequireWhenNotAnArray(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.5.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $satisPath = $tmpDir . '/satis.json';
        // "require" is set to a non-array value.
        file_put_contents($satisPath, '{"name":"my/satis","require":null}');

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $satisPath,
        ]);

        $updatedJson = (string) file_get_contents($satisPath);
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        $satisConfig = json_decode($updatedJson, true);
        self::assertIsArray($satisConfig);
        self::assertIsArray($satisConfig['require']);
        self::assertSame('>=1.5.0', $satisConfig['require']['vendor/pkg']);
    }

    /**
     * Kills mutant #7 (FalseValue on line 112 — $modified = false → true):
     * when the existing require already has the exact constraint, no write should occur
     * and "No changes needed." must be printed instead of "Updated".
     */
    public function testExecuteNonDryRunPrintsNoChangesWhenConstraintAlreadyCurrent(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $satisPath = $tmpDir . '/satis.json';
        $originalContent = json_encode(
            [
                'name' => 'my/satis',
                'require' => [
                    'vendor/pkg' => '>=1.0.0',
                ],
            ],
            JSON_THROW_ON_ERROR,
        );
        file_put_contents($satisPath, $originalContent);

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $satisPath,
        ]);

        $contentAfter = (string) file_get_contents($satisPath);
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No changes needed', $tester->getDisplay());
        self::assertStringNotContainsString('Updated', $tester->getDisplay());
        // The file must not have been rewritten.
        self::assertSame($originalContent, $contentAfter);
    }

    /**
     * Kills mutant #8 (NotIdentical on line 115 — !== → ===):
     * when an existing constraint differs, it must be overwritten with the new constraint.
     */
    public function testExecuteNonDryRunOverwritesStaleConstraint(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '2.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $satisPath = $tmpDir . '/satis.json';
        // Old stale constraint — different from what the lock file now says.
        file_put_contents($satisPath, json_encode(
            [
                'name' => 'my/satis',
                'require' => [
                    'vendor/pkg' => '>=1.0.0',
                ],
            ],
            JSON_THROW_ON_ERROR,
        ));

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $satisPath,
        ]);

        $updatedJson = (string) file_get_contents($satisPath);
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Updated', $tester->getDisplay());

        $satisConfig = json_decode($updatedJson, true);
        self::assertIsArray($satisConfig);
        self::assertIsArray($satisConfig['require']);
        // Must be the NEW constraint, not the stale one.
        self::assertSame('>=2.0.0', $satisConfig['require']['vendor/pkg']);
    }

    /**
     * Kills mutants #9-10 (BitwiseOr on line 127): the written JSON must use
     * JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE formatting.
     * Forward slashes must NOT be escaped; Unicode must NOT be escaped; output is indented.
     */
    public function testExecuteNonDryRunWritesPrettyJsonWithUnescapedSlashesAndUnicode(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $satisPath = $tmpDir . '/satis.json';
        // "homepage" contains a slash; "label" contains a non-ASCII Unicode character.
        file_put_contents($satisPath, json_encode(
            [
                'name' => 'my/satis',
                'homepage' => 'https://example.com/repo',
                'label' => 'héllo',
            ],
            JSON_THROW_ON_ERROR,
        ));

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $satisPath,
        ]);

        $written = (string) file_get_contents($satisPath);
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        // JSON_UNESCAPED_SLASHES: forward slash must not be escaped.
        self::assertStringContainsString('https://example.com/repo', $written);
        self::assertStringNotContainsString('https:\/\/', $written);
        // JSON_UNESCAPED_UNICODE: non-ASCII char must appear literally, not as a \uXXXX escape.
        self::assertStringContainsString('héllo', $written);
        self::assertStringNotContainsString('\u00e9', $written);
        // JSON_PRETTY_PRINT: output must be indented (contains newline + spaces).
        self::assertStringContainsString("\n    ", $written);
    }

    /**
     * Kills mutants #11-12 (Concat / ConcatOperandRemoval on line 135):
     * the file written to disk must end with a trailing newline.
     */
    public function testExecuteNonDryRunWritesTrailingNewline(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $satisPath = $tmpDir . '/satis.json';
        file_put_contents($satisPath, json_encode([
            'name' => 'my/satis',
        ], JSON_THROW_ON_ERROR));

        $command = new LockCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $satisPath,
        ]);

        $written = (string) file_get_contents($satisPath);
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        // The file must end with a single newline.
        self::assertSame("\n", substr($written, -1));
    }

    /**
     * Kills mutant #13 (Identical on line 137 — === false → !== false):
     * when file_put_contents fails, the error message must be printed and NOT the "Updated" message.
     */
    public function testExecuteNonDryRunReportsWriteFailure(): void
    {
        $tmpDir = $this->makeTempDir();
        $lockPath = $tmpDir . '/composer.lock';
        file_put_contents($lockPath, json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $satisPath = $tmpDir . '/satis.json';
        file_put_contents($satisPath, json_encode([
            'name' => 'my/satis',
        ], JSON_THROW_ON_ERROR));
        // Make the file unwritable so file_put_contents returns false.
        chmod($satisPath, 0o444);

        $command = new LockCommand();
        $tester = new CommandTester($command);

        // Suppress the E_WARNING from file_put_contents so PHPUnit does not treat it as a test failure.
        set_error_handler(static fn () => true);
        $exitCode = $tester->execute([
            '--lock' => $lockPath,
            '--config' => $satisPath,
        ]);
        restore_error_handler();

        chmod($satisPath, 0o644);
        $this->cleanupDir($tmpDir);

        self::assertSame(0, $exitCode);
        // The write-failure error message must appear...
        self::assertStringContainsString('Failed to write', $tester->getDisplay());
        // ...and on failure the success line must NOT (applyToSatisJson returns after the error).
        self::assertStringNotContainsString('Updated', $tester->getDisplay());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/lock_test_' . bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }

    /**
     * Build a small git repository where "vendor/risky" appears on lines attributed
     * to three distinct commits, causing LockAnalyzer::assessReversionRisk() to return true.
     *
     * Strategy: each commit touches exactly one line that contains "vendor/risky" so that
     * git-blame attributes those lines to three different commits (≥ 3 triggers isRisky).
     */
    private function createRiskyGitRepo(): string
    {
        $dir = sys_get_temp_dir() . '/lock_risky_' . bin2hex(random_bytes(4));
        mkdir($dir);

        $run = static function (string $cmd) use ($dir): void {
            shell_exec(sprintf('cd %s && %s 2>/dev/null', escapeshellarg($dir), $cmd));
        };

        $run('git init -q');
        $run('git config user.email "test@test.com"');
        $run('git config user.name "Test"');

        // Commit 1: introduce the "name" line for vendor/risky.
        // homepage and description are neutral placeholders (no "vendor/risky" in them yet).
        file_put_contents($dir . '/composer.lock', json_encode([
            'packages' => [[
                'name' => 'vendor/risky',
                'version' => '1.0.0',
                'homepage' => 'https://example.com',
                'description' => 'some description',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $run('git add composer.lock && git commit -q -m "c1-init"');

        // Commit 2: change ONLY the description to include "vendor/risky".
        // The name line stays as-is (blamed to c1); description is now blamed to c2.
        file_put_contents($dir . '/composer.lock', json_encode([
            'packages' => [[
                'name' => 'vendor/risky',
                'version' => '1.0.0',
                'homepage' => 'https://example.com',
                'description' => 'vendor/risky is great',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $run('git add composer.lock && git commit -q -m "c2-desc"');

        // Commit 3: change ONLY the homepage to include "vendor/risky".
        // name → c1, description → c2, homepage → c3.  Three distinct commits ≥ 3 → isRisky=true.
        file_put_contents($dir . '/composer.lock', json_encode([
            'packages' => [[
                'name' => 'vendor/risky',
                'version' => '1.0.0',
                'homepage' => 'https://github.com/vendor/risky',
                'description' => 'vendor/risky is great',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $run('git add composer.lock && git commit -q -m "c3-homepage"');

        return $dir;
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
