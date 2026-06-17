<?php

declare(strict_types=1);

namespace Satiate;

use Satiate\Command\AuditCommand;
use Satiate\Command\BuildCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public const string NAME = 'Satiate';

    public const string VERSION = '0.1.0-dev';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->addCommand(new BuildCommand());
        $this->addCommand(new AuditCommand());
    }
}
