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
}
