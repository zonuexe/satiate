<?php

declare(strict_types=1);

namespace Satiate\Command;

use Satiate\Lock\LockAnalyzer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class LockCommand extends Command
{
    public function __construct()
    {
        parent::__construct('lock');
    }

    protected function configure(): void
    {
        $this->setDescription('Derive minimum version constraints from composer.lock');

        $this->addOption('lock', null, InputOption::VALUE_REQUIRED, 'Path to composer.lock', 'composer.lock');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only, do not update satis.json');
        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to satis.json to update', 'satis.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lockPath = $input->getOption('lock');
        $configPath = $input->getOption('config');
        $dryRun = $input->getOption('dry-run') === true;

        if (! is_file($lockPath)) {
            $output->writeln(\sprintf('<error>Lock file not found: %s</error>', $lockPath));

            return self::FAILURE;
        }

        $analyzer = new LockAnalyzer();
        $projectDir = \dirname(realpath($lockPath));
        $constraints = $analyzer->analyze($lockPath, $projectDir);

        if ($constraints === []) {
            $output->writeln('<info>No packages found in lock file.</info>');

            return self::SUCCESS;
        }

        $output->writeln(\sprintf('Found <info>%d</info> locked packages:', \count($constraints)));

        $updates = [];

        foreach ($constraints as $constraint) {
            $riskFlag = $constraint->isRisky ? ' <comment>[reversion risk]</comment>' : '';
            $output->writeln(\sprintf(
                '  %s: %s%s',
                $constraint->name,
                $constraint->constraint,
                $riskFlag,
            ));

            $updates[$constraint->name] = $constraint->constraint;
        }

        if (! $dryRun && is_file($configPath)) {
            $this->applyToSatisJson($configPath, $updates, $output);
        }

        if ($dryRun) {
            $output->writeln("\n<info>Dry-run mode — no files modified.</info>");
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $updates
     */
    private function applyToSatisJson(string $configPath, array $updates, OutputInterface $output): void
    {
        $contents = file_get_contents($configPath);

        if ($contents === false) {
            $output->writeln(\sprintf('<error>Failed to read %s</error>', $configPath));

            return;
        }

        $config = json_decode($contents, true);

        if (! is_array($config)) {
            $output->writeln(\sprintf('<error>Invalid JSON in %s</error>', $configPath));

            return;
        }

        if (! isset($config['require']) || ! is_array($config['require'])) {
            $config['require'] = [];
        }

        $modified = false;

        foreach ($updates as $packageName => $constraint) {
            if (! isset($config['require'][$packageName]) || $config['require'][$packageName] !== $constraint) {
                $config['require'][$packageName] = $constraint;
                $modified = true;
            }
        }

        if (! $modified) {
            $output->writeln('<info>No changes needed.</info>');

            return;
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $output->writeln('<error>Failed to encode updated config.</error>');

            return;
        }

        $written = file_put_contents($configPath, $json . "\n");

        if ($written === false) {
            $output->writeln(\sprintf('<error>Failed to write %s</error>', $configPath));
        }

        $output->writeln(\sprintf('<info>Updated %s</info>', $configPath));
    }
}
