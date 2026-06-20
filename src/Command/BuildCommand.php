<?php

declare(strict_types=1);

namespace Satiate\Command;

use Satiate\Audit\Severity;
use Satiate\Build\BuildRunner;
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
        $this->addOption('no-audit-cache', null, InputOption::VALUE_NONE, 'Audit every package every run instead of skipping versions recorded in .satiate-cache');
        $this->addOption('include-dev', null, InputOption::VALUE_NONE, 'Include dev dependencies');
        $this->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit non-zero if the audit finds an issue at or above this severity (info, warning, critical)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configPath = $input->getOption('config');
        $outputDir = $input->getOption('output-dir');

        if (! \is_string($configPath)) {
            $output->writeln('<error>Invalid config path.</error>');

            return self::FAILURE;
        }

        if (! \is_string($outputDir)) {
            $output->writeln('<error>Invalid output directory.</error>');

            return self::FAILURE;
        }

        try {
            $config = ConfigLoader::load($configPath);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $includeDev = $input->getOption('include-dev') === true;
        $runAudit = $input->getOption('no-audit') !== true;
        $useAuditCache = $input->getOption('no-audit-cache') !== true;

        // --fail-on is optional: when unset, audit findings never change the exit code.
        $failOnInput = $input->getOption('fail-on');
        $failOn = null;

        if (\is_string($failOnInput) && $failOnInput !== '') {
            $failOn = Severity::tryFrom(strtolower($failOnInput));

            if ($failOn === null) {
                $output->writeln(\sprintf(
                    '<error>Invalid --fail-on "%s"; expected one of: info, warning, critical</error>',
                    $failOnInput,
                ));

                return self::FAILURE;
            }
        }

        $output->writeln(\sprintf('Building repository: <info>%s</info>', $config->name));
        $output->writeln(\sprintf('Output directory: <info>%s</info>', $outputDir));
        $output->writeln(\sprintf('Packages required: <info>%d</info>', \count($config->require)));
        $output->writeln(\sprintf('Include dev: <info>%s</info>', $includeDev ? 'yes' : 'no'));
        $output->writeln(\sprintf('Audit: <info>%s</info>', $runAudit ? 'enabled' : 'disabled'));

        $runner = new BuildRunner(
            config: $config,
            outputDir: $outputDir,
            includeDev: $includeDev,
            runAudit: $runAudit,
            useAuditCache: $useAuditCache,
        );

        try {
            $result = $runner->run();
        } catch (\RuntimeException $e) {
            $output->writeln(\sprintf('<error>Build failed: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $summary = $runner->lastAuditSummary;

        if ($summary->total() > 0) {
            $output->writeln(\sprintf(
                '<comment>Audit: %d suspicious pattern(s) found (%d critical, %d warning, %d info).</comment>',
                $summary->total(),
                $summary->count(Severity::Critical),
                $summary->count(Severity::Warning),
                $summary->count(Severity::Info),
            ));
        }

        if ($runner->lastCapabilityChanges !== []) {
            $output->writeln('<comment>Capability changes (a new version gained a capability the previous one lacked):</comment>');

            foreach ($runner->lastCapabilityChanges as $change) {
                $output->writeln(\sprintf(
                    '  <comment>%s %s introduces "%s" (not in %s)</comment>',
                    $change['package'],
                    $change['version'],
                    $change['capability'],
                    $change['previousVersion'],
                ));
            }
        }

        if ($failOn !== null && $summary->countAtOrAbove($failOn) > 0) {
            $output->writeln(\sprintf(
                '<error>Audit gate failed: %d finding(s) at or above "%s".</error>',
                $summary->countAtOrAbove($failOn),
                $failOn->value,
            ));

            return self::FAILURE;
        }

        if ($result === 0) {
            $output->writeln('<info>Build completed successfully.</info>');
        }

        return $result;
    }
}
