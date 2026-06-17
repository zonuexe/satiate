<?php

declare(strict_types=1);

namespace Satiate\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Application;
use Satiate\Command\BuildCommand;

#[CoversClass(Application::class)]
#[CoversClass(BuildCommand::class)]
final class ApplicationTest extends TestCase
{
    public function testApplicationHasBuildCommand(): void
    {
        $application = new Application();
        $command = $application->get('build');

        self::assertInstanceOf(BuildCommand::class, $command);
    }

    public function testApplicationNameAndVersion(): void
    {
        $application = new Application();

        self::assertSame('Satiate', $application->getName());
        self::assertSame('0.1.0-dev', $application->getVersion());
    }

    public function testBuildCommandIsRegistered(): void
    {
        $application = new Application();

        self::assertTrue($application->has('build'));
    }
}
