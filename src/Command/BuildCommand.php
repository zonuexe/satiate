<?php

declare(strict_types=1);

namespace Satiate\Command;

use Satiate\Config\ConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to satis.json', 'satis.json');
        $this->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Output directory', 'output');
        $this->addOption('no-audit', null, InputOption::VALUE_NONE, 'Skip audit step');
        $this->addOption('include-dev', null, InputOption::VALUE_NONE, 'Include dev dependencies');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $configPath */
        $configPath = $input->getOption('config');
        /** @var string $outputDir */
        $outputDir = $input->getOption('output-dir');

        try {
            $config = ConfigLoader::load($configPath);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(\sprintf('Building repository: <info>%s</info>', $config->name));
        $output->writeln(\sprintf('Output directory: <info>%s</info>', $outputDir));
        $output->writeln(\sprintf('Packages required: <info>%d</info>', \count($config->require)));

        $includeDev = $input->getOption('include-dev') === true;
        $runAudit = $input->getOption('no-audit') !== true;

        $output->writeln(\sprintf('Include dev: <info>%s</info>', $includeDev ? 'yes' : 'no'));
        $output->writeln(\sprintf('Audit: <info>%s</info>', $runAudit ? 'enabled' : 'disabled'));

        return self::SUCCESS;
    }
}
