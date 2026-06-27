<?php

declare(strict_types=1);

namespace Satiate\Command;

use Satiate\Audit\Auditor;
use Satiate\Audit\AuditSummary;
use Satiate\Audit\Parallel\AuditExecutor;
use Satiate\Audit\Parallel\AuditTarget;
use Satiate\Audit\Parallel\AuditTargetKind;
use Satiate\Audit\Severity;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AuditCommand extends Command
{
    public function __construct()
    {
        parent::__construct('audit');
    }

    protected function configure(): void
    {
        $this->setDescription('Audit packages for suspicious code patterns');

        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to satis.json', 'satis.json');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to package source to audit');
        $this->addOption('cache-path', null, InputOption::VALUE_REQUIRED, 'Path to .satiate-cache for change-diff auditing');
        $this->addOption('min-severity', null, InputOption::VALUE_REQUIRED, 'Only list findings at or above this severity (info, warning, critical)', Severity::Info->value);
        $this->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit non-zero if any finding is at or above this severity (info, warning, critical)');
        $this->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Audit targets in parallel across N worker processes (1 = sequential)', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('path');
        $cachePath = $input->getOption('cache-path');

        if (! \is_string($path) || $path === '') {
            $output->writeln('<error>--path is required for standalone audit</error>');

            return self::FAILURE;
        }

        if (! is_dir($path)) {
            $output->writeln(\sprintf('<error>Path not found: %s</error>', $path));

            return self::FAILURE;
        }

        $minSeverityInput = $input->getOption('min-severity');
        $minSeverity = \is_string($minSeverityInput) ? Severity::tryFrom(strtolower($minSeverityInput)) : null;

        if ($minSeverity === null) {
            $output->writeln(\sprintf(
                '<error>Invalid --min-severity "%s"; expected one of: info, warning, critical</error>',
                \is_string($minSeverityInput) ? $minSeverityInput : '',
            ));

            return self::FAILURE;
        }

        // --fail-on is optional (no default): when unset, findings never change the exit code.
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

        $jobsInput = $input->getOption('jobs');
        $jobs = \is_string($jobsInput) ? filter_var($jobsInput, FILTER_VALIDATE_INT) : false;

        if ($jobs === false || $jobs < 1) {
            $output->writeln(\sprintf(
                '<error>Invalid --jobs "%s"; expected a positive integer.</error>',
                \is_string($jobsInput) ? $jobsInput : '',
            ));

            return self::FAILURE;
        }

        $auditedFiles = [];

        if (\is_string($cachePath) && $cachePath !== '' && is_file($cachePath . '/audited-files.json')) {
            $cacheContent = file_get_contents($cachePath . '/audited-files.json');

            if ($cacheContent !== false) {
                $decoded = json_decode($cacheContent, true);

                if (is_array($decoded)) {
                    $auditedFiles = $decoded;
                }
            }
        }

        $summary = new AuditSummary();
        $shownResults = 0;
        $files = $this->auditTargetsIn($path);
        $newlyAudited = [];

        if ($files === []) {
            $output->writeln(\sprintf('<info>No PHP files found in %s</info>', $path));

            return self::SUCCESS;
        }

        // Skip targets whose mtime matches the cache; keep the survivors in sorted order so output
        // and cache writes stay deterministic regardless of how the audit below is scheduled.
        $pending = [];
        $mtimes = [];

        foreach ($files as $file) {
            $mtime = filemtime($file);

            if (isset($auditedFiles[$file]) && $auditedFiles[$file] === $mtime) {
                continue;
            }

            $pending[] = $file;
            $mtimes[$file] = $mtime;
        }

        // The audit itself is the only parallel part: when --jobs > 1 each target is parsed in its
        // own worker process. Aggregation, output, and caching below stay sequential and ordered.
        // A built mirror stores package code as zip/tar archives under dist/, audited inside them; a
        // plain source tree is audited file by file (composer.json included) — AuditTargetKind picks.
        $targets = [];

        foreach ($pending as $file) {
            $targets[] = new AuditTarget($file, AuditTargetKind::forPath($file));
        }

        $resultsByFile = (new AuditExecutor($jobs))->run($targets);

        foreach ($pending as $file) {
            foreach ($resultsByFile[$file] as $result) {
                $summary->add($result);

                // Every finding is counted for the summary, but only those at or above the
                // requested threshold are listed individually.
                if ($result->severity->rank() < $minSeverity->rank()) {
                    continue;
                }

                $tag = match ($result->severity) {
                    Severity::Critical => 'error',
                    Severity::Warning => 'comment',
                    Severity::Info => 'info',
                };

                $output->writeln(\sprintf(
                    '  [<%s>%s</%s>] %s:%d — %s',
                    $tag,
                    $result->severity->value,
                    $tag,
                    $result->file,
                    $result->line,
                    $result->description,
                ));

                $shownResults++;
            }

            $newlyAudited[$file] = $mtimes[$file];
        }

        if (\is_string($cachePath) && $cachePath !== '' && $newlyAudited !== []) {
            if (! is_dir($cachePath)) {
                mkdir($cachePath, 0755, true);
            }

            $merged = array_merge($auditedFiles, $newlyAudited);
            file_put_contents($cachePath . '/audited-files.json', json_encode($merged, JSON_PRETTY_PRINT));
        }

        if ($summary->total() === 0) {
            $output->writeln('<info>No suspicious patterns detected.</info>');

            return self::SUCCESS;
        }

        $output->writeln(\sprintf(
            "\n<comment>%d issue(s) found.</comment> (%d critical, %d warning, %d info)",
            $summary->total(),
            $summary->count(Severity::Critical),
            $summary->count(Severity::Warning),
            $summary->count(Severity::Info),
        ));

        $hidden = $summary->total() - $shownResults;

        if ($hidden > 0) {
            $output->writeln(\sprintf(
                '<info>Showing %d at or above "%s"; %d hidden by --min-severity.</info>',
                $shownResults,
                $minSeverity->value,
                $hidden,
            ));
        }

        if ($failOn !== null && $summary->countAtOrAbove($failOn) > 0) {
            $output->writeln(\sprintf(
                "\n<error>Audit gate failed: %d finding(s) at or above \"%s\".</error>",
                $summary->countAtOrAbove($failOn),
                $failOn->value,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Collect everything auditable under $path: loose PHP source files, composer.json manifests,
     * and the distribution archives `satiate build` writes under dist/ (zip, tar, tar.gz, tar.bz2).
     * Other files — repository JSON metadata, the WebUI — are ignored.
     *
     * @return list<string>
     */
    private function auditTargetsIn(string $path): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);

            $name = $file->getFilename();

            if ($file->isFile() && (str_ends_with($name, '.php') || $name === 'composer.json' || Auditor::isSupportedArchive($name))) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
