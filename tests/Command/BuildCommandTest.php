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

    public function testExecuteWithValidConfig(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--config' => $this->fixtureDir . '/valid.json',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Building repository', $tester->getDisplay());
        self::assertStringContainsString('My Repository', $tester->getDisplay());
    }

    public function testExecuteWithMinimalConfig(): void
    {
        $command = new BuildCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--config' => $this->fixtureDir . '/minimal.json',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Building repository', $tester->getDisplay());
    }
}
