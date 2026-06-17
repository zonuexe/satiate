<?php

declare(strict_types=1);

namespace Satiate\Command;

use Satiate\Audit\Auditor;
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

        $auditor = new Auditor();
        $totalResults = 0;
        $files = $this->phpFilesIn($path);
        $newlyAudited = [];

        if ($files === []) {
            $output->writeln(\sprintf('<info>No PHP files found in %s</info>', $path));

            return self::SUCCESS;
        }

        foreach ($files as $file) {
            $mtime = filemtime($file);

            if (isset($auditedFiles[$file]) && $auditedFiles[$file] === $mtime) {
                continue;
            }

            $results = $auditor->auditFile('', '', $file);

            foreach ($results as $result) {
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

                $totalResults++;
            }

            $newlyAudited[$file] = $mtime;
        }

        if (\is_string($cachePath) && $cachePath !== '' && $newlyAudited !== []) {
            if (!is_dir($cachePath)) {
                mkdir($cachePath, 0755, true);
            }

            $merged = array_merge($auditedFiles, $newlyAudited);
            file_put_contents($cachePath . '/audited-files.json', json_encode($merged, JSON_PRETTY_PRINT));
        }

        if ($totalResults === 0) {
            $output->writeln('<info>No suspicious patterns detected.</info>');

            return self::SUCCESS;
        }

        $output->writeln(\sprintf("\n<comment>%d issue(s) found.</comment>", $totalResults));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $path): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var list<string> $files */
        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
