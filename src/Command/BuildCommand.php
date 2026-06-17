<?php

declare(strict_types=1);

namespace Satiate\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class BuildCommand extends Command
{
    public function __construct()
    {
        parent::__construct('build');
    }

    protected function configure(): void
    {
        $this->setDescription('Build static Composer repository from satis.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Build command not yet implemented.</info>');

        return self::SUCCESS;
    }
}
