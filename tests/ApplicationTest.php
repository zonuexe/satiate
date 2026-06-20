<?php

declare(strict_types=1);

namespace Satiate\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Application;
use Satiate\Command\AuditCommand;
use Satiate\Command\BuildCommand;
use Satiate\Command\LockCommand;

#[CoversClass(Application::class)]
#[CoversClass(BuildCommand::class)]
#[CoversClass(AuditCommand::class)]
#[CoversClass(LockCommand::class)]
final class ApplicationTest extends TestCase
{
    public function testApplicationHasBuildCommand(): void
    {
        $application = new Application();
        $command = $application->get('build');

        self::assertInstanceOf(BuildCommand::class, $command);
    }

    public function testApplicationHasAuditCommand(): void
    {
        $application = new Application();
        $command = $application->get('audit');

        self::assertInstanceOf(AuditCommand::class, $command);
    }

    public function testApplicationHasLockCommand(): void
    {
        $application = new Application();
        $command = $application->get('lock');

        self::assertInstanceOf(LockCommand::class, $command);
    }

    public function testApplicationNameAndVersion(): void
    {
        $application = new Application();

        self::assertSame('Satiate', $application->getName());
        self::assertSame('0.0.1', $application->getVersion());
    }

    public function testAllCommandsAreRegistered(): void
    {
        $application = new Application();

        self::assertTrue($application->has('build'));
        self::assertTrue($application->has('audit'));
        self::assertTrue($application->has('lock'));
    }
}
