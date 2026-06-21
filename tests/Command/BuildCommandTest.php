<?php

declare(strict_types=1);

namespace Satiate\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Command\BuildCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BuildCommand::class)]
final class BuildCommandTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/../Config/fixtures';
    }

    public function testCommandName(): void
    {
        $command = new BuildCommand();
        self::assertSame('build', $command->getName());
    }

    public function testConfigureRegistersDescriptionAndOptions(): void
    {
        $command = new BuildCommand();
        self::assertSame('Build static Composer repository from satis.json', $command->getDescription());

        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('config'));
        self::assertSame('c', $definition->getOption('config')->getShortcut());
        self::assertTrue($definition->getOption('config')->isValueRequired());
        self::assertSame('satis.json', $definition->getOption('config')->getDefault());

        self::assertTrue($definition->hasOption('output-dir'));
        self::assertTrue($definition->getOption('output-dir')->isValueRequired());
        self::assertSame('output', $definition->getOption('output-dir')->getDefault());

        self::assertTrue($definition->hasOption('no-audit'));
        self::assertFalse($definition->getOption('no-audit')->acceptValue());

        self::assertTrue($definition->hasOption('include-dev'));
        self::assertFalse($definition->getOption('include-dev')->acceptValue());

        self::assertTrue($definition->hasOption('no-audit-cache'));
        self::assertFalse($definition->getOption('no-audit-cache')->acceptValue());

        self::assertTrue($definition->hasOption('fail-on'));
        self::assertTrue($definition->getOption('fail-on')->isValueRequired());
        // No default: without --fail-on the exit code never changes because of audit findings.
        self::assertNull($definition->getOption('fail-on')->getDefault());

        self::assertTrue($definition->hasOption('jobs'));
        self::assertSame('j', $definition->getOption('jobs')->getShortcut());
        self::assertTrue($definition->getOption('jobs')->isValueRequired());
        self::assertSame('1', $definition->getOption('jobs')->getDefault());
    }

    public function testInvalidJobsValueReturnsError(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--config' => '/nonexistent/satis.json',
            '--jobs' => 'nope',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid --jobs', $tester->getDisplay());
    }

    public function testExecuteWithNonExistentConfig(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--config' => '/nonexistent/satis.json',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    /**
     * Test that the error message from a failed ConfigLoader::load() is wrapped in error styling.
     * With decorated output, the <error> tag renders as ANSI escape codes around the message text.
     * This kills concat-mutants that strip the opening <error> tag prefix or reorder the tags.
     */
    public function testExecuteConfigLoadErrorIsStyledAsError(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(
            [
                '--config' => '/nonexistent/path/to/satis.json',
            ],
            [
                'decorated' => true,
            ],
        );

        self::assertSame(1, $exitCode);
        $display = $tester->getDisplay(true);

        // The error message text must appear in the output
        self::assertStringContainsString('/nonexistent/path/to/satis.json', $display);

        // With decoration enabled, a proper <error>...</error> wrap produces ANSI styling codes
        // around the message. If the opening <error> tag is missing (mutant 1) or the message
        // is placed outside the tags (mutants 2 & 3), no ANSI error-style codes appear around it.
        // The error style opens with ESC[37;41m (white on red).
        self::assertStringContainsString("\033[37;41m", $display);
        // And it must be closed before the newline.
        self::assertStringContainsString("\033[39;49m", $display);
        // The filename must appear BETWEEN the opening and closing codes, not after them.
        // i.e. the sequence is: <open-ansi>...<filename>...<close-ansi>
        $openPos = strpos($display, "\033[37;41m");
        $closePos = strpos($display, "\033[39;49m");
        $filePos = strpos($display, '/nonexistent/path/to/satis.json');
        self::assertNotFalse($openPos);
        self::assertNotFalse($closePos);
        self::assertNotFalse($filePos);
        self::assertGreaterThan($openPos, $filePos, 'Filename must appear after the error-style open code');
        self::assertLessThan($closePos, $filePos, 'Filename must appear before the error-style close code');
    }

    public function testExecuteWithValidConfig(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--config' => $this->fixtureDir . '/valid.json',
        ]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();

        // Verify the "Building repository" line includes the config name
        self::assertStringContainsString('Building repository', $display);
        self::assertStringContainsString('My Repository', $display);

        // Verify the output directory line is present (mutant 4: MethodCallRemoval)
        self::assertStringContainsString('Output directory:', $display);

        // Verify the packages required line is present (mutant 5: MethodCallRemoval)
        self::assertStringContainsString('Packages required:', $display);

        // Verify include-dev defaults to "no" when flag is not passed (mutants 6-7)
        self::assertStringContainsString('Include dev: no', $display);

        // Verify audit defaults to "enabled" when --no-audit is not passed (mutants 8-9)
        self::assertStringContainsString('Audit: enabled', $display);

        // Verify the success message is shown for exit code 0 (mutants 12-14)
        self::assertStringContainsString('Build completed successfully.', $display);
    }

    public function testExecuteWithMinimalConfig(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
        ]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Building repository', $display);

        // Success message is shown (mutants 12-14)
        self::assertStringContainsString('Build completed successfully.', $display);
    }

    /**
     * When --include-dev is passed, the display must say "yes", not "no".
     * Kills mutant 6 (Ternary: swaps yes/no branches).
     */
    public function testExecuteIncludeDevYesWhenFlagPassed(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
            '--include-dev' => true,
        ]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Include dev: yes', $display);
        // Confirm "no" is NOT in the include-dev line
        self::assertStringNotContainsString('Include dev: no', $display);
    }

    /**
     * Without --include-dev the display must say "no".
     * Together with testExecuteIncludeDevYesWhenFlagPassed this kills mutant 6.
     */
    public function testExecuteIncludeDevNoByDefault(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
        ]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Include dev: no', $display);
        self::assertStringNotContainsString('Include dev: yes', $display);
    }

    /**
     * When --no-audit is passed the display must say "disabled", not "enabled".
     * Kills mutant 8 (Ternary: swaps enabled/disabled branches).
     */
    public function testExecuteNoAuditShowsDisabledLabel(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
            '--no-audit' => true,
        ]);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Audit: disabled', $display);
        self::assertStringNotContainsString('Audit: enabled', $display);
    }

    /**
     * Without --no-audit the display must say "enabled".
     * Together with testExecuteNoAuditShowsDisabledLabel this kills mutant 8.
     */
    public function testExecuteAuditEnabledByDefault(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
        ]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Audit: enabled', $display);
        self::assertStringNotContainsString('Audit: disabled', $display);
    }

    /**
     * The specific --output-dir value must appear in the display.
     * Kills mutant 4 (MethodCallRemoval for the "Output directory:" writeln call).
     */
    public function testExecuteOutputDirectoryAppearsInDisplay(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $outputDir = sys_get_temp_dir() . '/satiate_test_' . uniqid('', true);

        $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
            '--output-dir' => $outputDir,
        ]);

        $display = $tester->getDisplay();

        // The specific output-dir value must appear in the display (mutant 4)
        self::assertStringContainsString('Output directory:', $display);
        self::assertStringContainsString($outputDir, $display);
    }

    /**
     * The exact package count from "require" must appear in the output.
     * valid.json has exactly 1 entry — kills mutant 5 (MethodCallRemoval).
     */
    public function testExecutePackagesRequiredCountAppearsInDisplay(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            '--config' => $this->fixtureDir . '/valid.json',
        ]);

        $display = $tester->getDisplay();

        // valid.json has exactly 1 entry in "require" (mutant 5: MethodCallRemoval)
        self::assertStringContainsString('Packages required: 1', $display);
    }

    /**
     * Success message must NOT appear when the command fails.
     * Kills mutant 12 (Identical negation: result === 0 → result !== 0).
     */
    public function testSuccessMessageNotShownOnFailure(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--config' => '/nonexistent/satis.json',
        ]);

        // Exit code must be FAILURE (1), not SUCCESS (0)
        self::assertSame(1, $exitCode);

        // Success message must NOT appear on failure
        self::assertStringNotContainsString('Build completed successfully.', $tester->getDisplay());
    }

    /**
     * Audit findings comment line should only appear when lastAuditFindings > 0.
     * This is hard to trigger via integration since build requires real packages.
     * We test the inverse: with --no-audit, no "Audit: X suspicious pattern(s)" comment appears.
     * Mutants 10-11 (GreaterThan / GreaterThanNegotiation on `> 0`) are guarded by the
     * fact that a zero-finding run (no packages resolved from fixture) never prints the comment.
     */
    public function testNoAuditFindingsCommentWhenNoPackagesResolved(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        // minimal.json resolves zero real packages (no repositories defined),
        // so lastAuditFindings stays 0 and no comment should appear.
        $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
        ]);

        $display = $tester->getDisplay();

        // The audit findings comment must NOT appear when there are 0 findings
        self::assertStringNotContainsString('suspicious pattern(s) found', $display);
    }

    // -------------------------------------------------------------------------
    // --fail-on gate: reflect audit findings in the build exit code
    // -------------------------------------------------------------------------

    public function testInvalidFailOnReturnsError(): void
    {
        $outDir = sys_get_temp_dir() . '/build_gate_' . bin2hex(random_bytes(4));

        $command = new BuildCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
            '--output-dir' => $outDir,
            '--fail-on' => 'bogus',
        ]);

        $display = $tester->getDisplay();

        if (is_dir($outDir)) {
            $this->rmrf($outDir);
        }

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid --fail-on', $display);
    }

    public function testFailOnDoesNotTripBuildWhenNoAuditFindings(): void
    {
        // minimal.json resolves zero packages, so there are no findings and the gate passes.
        $outDir = sys_get_temp_dir() . '/build_gate_' . bin2hex(random_bytes(4));

        $command = new BuildCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
            '--output-dir' => $outDir,
            '--fail-on' => 'critical',
        ]);

        $display = $tester->getDisplay();

        if (is_dir($outDir)) {
            $this->rmrf($outDir);
        }

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Build completed successfully.', $display);
        self::assertStringNotContainsString('Audit gate failed', $display);
    }

    /**
     * Integration: build a mirror from a local path package whose code contains eval() (critical),
     * and confirm `--fail-on critical` makes the build exit non-zero. No network is needed — the
     * package is served from a local `path` repository.
     */
    public function testFailOnCriticalTripsBuildExitCodeOnAuditFinding(): void
    {
        $work = sys_get_temp_dir() . '/build_gate_' . bin2hex(random_bytes(4));
        $satisPath = $this->writeEvilMirrorFixture($work);

        $command = new BuildCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--config' => $satisPath,
            '--output-dir' => $work . '/out',
            '--fail-on' => 'critical',
        ]);

        $display = $tester->getDisplay();
        $this->rmrf($work);

        self::assertSame(1, $exitCode);
        // The audit found the eval() and the gate tripped...
        self::assertStringContainsString('Audit gate failed', $display);
        self::assertStringContainsString('at or above "critical"', $display);
        // ...so the build does not claim success.
        self::assertStringNotContainsString('Build completed successfully.', $display);
    }

    /**
     * The same build without --fail-on still reports the finding but exits 0 (backward compatible).
     */
    public function testAuditFindingDoesNotAffectExitCodeWithoutFailOn(): void
    {
        $work = sys_get_temp_dir() . '/build_gate_' . bin2hex(random_bytes(4));
        $satisPath = $this->writeEvilMirrorFixture($work);

        $command = new BuildCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--config' => $satisPath,
            '--output-dir' => $work . '/out',
        ]);

        $display = $tester->getDisplay();
        $this->rmrf($work);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('suspicious pattern(s) found', $display);
        self::assertStringContainsString('Build completed successfully.', $display);
        self::assertStringNotContainsString('Audit gate failed', $display);
    }

    /**
     * The audit cache records each audited name:version, so a second build over the same output
     * directory skips already-audited packages and the gate would not re-trip. --no-audit-cache
     * forces a full re-audit, so the gate trips again on the rebuild.
     */
    public function testNoAuditCacheReauditsSoGateTripsOnRebuild(): void
    {
        $work = sys_get_temp_dir() . '/build_gate_' . bin2hex(random_bytes(4));
        $satisPath = $this->writeEvilMirrorFixture($work);
        $outDir = $work . '/out';

        $run = function (array $extra) use ($satisPath, $outDir): array {
            $tester = new CommandTester(new BuildCommand());
            $exit = $tester->execute([
                '--config' => $satisPath,
                '--output-dir' => $outDir,
                '--fail-on' => 'critical',
            ] + $extra);

            return [$exit, $tester->getDisplay()];
        };

        // First build: nothing cached yet, the eval() is audited and the gate trips.
        [$firstExit] = $run([]);

        // Rebuild with the cache (default): the version is already audited, so it is skipped and
        // the gate sees no findings.
        [$cachedExit, $cachedDisplay] = $run([]);

        // Rebuild with --no-audit-cache: the package is audited again and the gate trips once more.
        [$freshExit, $freshDisplay] = $run([
            '--no-audit-cache' => true,
        ]);

        $this->rmrf($work);

        self::assertSame(1, $firstExit);
        self::assertSame(0, $cachedExit, 'cached rebuild should not re-trip the gate');
        self::assertStringNotContainsString('Audit gate failed', $cachedDisplay);
        self::assertSame(1, $freshExit, '--no-audit-cache rebuild should re-trip the gate');
        self::assertStringContainsString('Audit gate failed', $freshDisplay);
    }

    /**
     * --fail-on is case-insensitive: an upper-case severity is normalised before matching, so
     * `--fail-on CRITICAL` trips the gate exactly like `critical`.
     */
    public function testFailOnSeverityIsCaseInsensitive(): void
    {
        $work = sys_get_temp_dir() . '/build_gate_' . bin2hex(random_bytes(4));
        $satisPath = $this->writeEvilMirrorFixture($work);

        $command = new BuildCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--config' => $satisPath,
            '--output-dir' => $work . '/out',
            '--fail-on' => 'CRITICAL',
        ]);

        $display = $tester->getDisplay();
        $this->rmrf($work);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Audit gate failed', $display);
        self::assertStringNotContainsString('Invalid --fail-on', $display);
    }

    /**
     * Cross-build version diff: build a clean v1.0.0, then rebuild the same mirror after the
     * package's v2.0.0 gains eval(). The build must report the newly-introduced capability,
     * comparing the new version against the previous one's cached fingerprint.
     */
    public function testBuildReportsCapabilityIntroducedByANewVersion(): void
    {
        $work = sys_get_temp_dir() . '/build_vdiff_' . bin2hex(random_bytes(4));
        $pkgDir = $work . '/pkg';
        $outDir = $work . '/out';
        mkdir($pkgDir, 0755, true);

        $satisPath = $work . '/satis.json';
        file_put_contents($satisPath, (string) json_encode([
            'name' => 'VDiff',
            'homepage' => 'http://localhost',
            'repositories' => [[
                'type' => 'path',
                'url' => $pkgDir,
            ]],
            'require' => [
                'acme/widget' => '*',
            ],
            'require-dependencies' => false,
            'max-versions-per-package' => 5,
            'archive' => [
                'directory' => 'dist',
                'format' => 'zip',
            ],
        ]));

        $build = function () use ($satisPath, $outDir): string {
            $tester = new CommandTester(new BuildCommand());
            $tester->execute([
                '--config' => $satisPath,
                '--output-dir' => $outDir,
            ]);

            return $tester->getDisplay();
        };

        // Build 1: a clean v1.0.0.
        file_put_contents($pkgDir . '/composer.json', (string) json_encode([
            'name' => 'acme/widget',
            'version' => '1.0.0',
        ]));
        file_put_contents($pkgDir . '/Widget.php', '<?php class Widget { public function hi() { return 1; } }');
        $firstDisplay = $build();

        // Build 2: v2.0.0 gains eval().
        file_put_contents($pkgDir . '/composer.json', (string) json_encode([
            'name' => 'acme/widget',
            'version' => '2.0.0',
        ]));
        file_put_contents($pkgDir . '/Widget.php', '<?php class Widget { public function hi() { eval($GLOBALS["x"]); } }');
        $secondDisplay = $build();

        $this->rmrf($work);

        // The clean first build reports no capability change...
        self::assertStringNotContainsString('Capability changes', $firstDisplay);
        // ...the second build flags the newly-introduced eval against the cached v1.0.0 fingerprint.
        self::assertStringContainsString('Capability changes', $secondDisplay);
        self::assertStringContainsString('acme/widget 2.0.0 introduces "eval" (not in 1.0.0)', $secondDisplay);
    }

    /**
     * Writes a local path-repo package whose code contains eval() (a critical finding) and a
     * satis.json that mirrors it, under $work. Returns the satis.json path.
     */
    private function writeEvilMirrorFixture(string $work): string
    {
        $pkgDir = $work . '/pkg';
        mkdir($pkgDir, 0755, true);

        file_put_contents($pkgDir . '/composer.json', (string) json_encode([
            'name' => 'satiate/evil-fixture',
            'version' => '1.0.0',
        ]));
        file_put_contents($pkgDir . '/Evil.php', '<?php eval($x);');

        $satisPath = $work . '/satis.json';
        file_put_contents($satisPath, (string) json_encode([
            'name' => 'Gate Test',
            'homepage' => 'http://localhost',
            'repositories' => [[
                'type' => 'path',
                'url' => $pkgDir,
            ]],
            'require' => [
                'satiate/evil-fixture' => '1.0.0',
            ],
            'require-all' => false,
            'require-dependencies' => false,
            'archive' => [
                'directory' => 'dist',
                'format' => 'zip',
            ],
        ]));

        return $satisPath;
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
