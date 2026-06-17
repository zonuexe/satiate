<?php

declare(strict_types=1);

namespace Satiate;

use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public const string NAME = 'Satiate';

    public const string VERSION = '0.1.0-dev';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
    }
}
