<?php

declare(strict_types=1);

namespace Satiate\Audit;

use PhpParser\Node;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

final class Auditor
{
    /**
     * @var list<AuditResult>
     */
    private array $results = [];

    private string $currentFile = '';

    /**
     * Audit a single file for suspicious patterns.
     *
     * @return list<AuditResult>
     */
    public function auditFile(string $package, string $version, string $filePath): array
    {
        if (! is_file($filePath) || ! str_ends_with($filePath, '.php')) {
            return [];
        }

        $contents = file_get_contents($filePath);

        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $this->results = [];
        $this->currentFile = $filePath;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $traverser = new NodeTraverser();

        $traverser->addVisitor(new class($this, $contents) extends NodeVisitorAbstract {
            public function __construct(
                private readonly Auditor $auditor,
                private readonly string $contents,
            ) {}

            public function enterNode(Node $node): void
            {
                if ($node instanceof Eval_) {
                    $this->auditor->addResult(
                        pattern: 'eval',
                        line: $node->getStartLine(),
                        description: 'Use of eval() detected — possible code injection',
                        severity: Severity::Critical,
                    );
                }

                if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
                    $functionName = $node->name->toLowerString();
                    $line = $node->getStartLine();

                    match ($functionName) {
                        'create_function' => $this->auditor->addResult(
                            pattern: 'create_function',
                            line: $line,
                            description: 'Use of create_function() — deprecated, possible code injection',
                            severity: Severity::Critical,
                        ),
                        'assert' => $this->auditor->addResult(
                            pattern: 'assert',
                            line: $line,
                            description: 'Use of assert() — may indicate debug code or code injection',
                            severity: Severity::Info,
                        ),
                        'base64_decode' => $this->checkEncodedPayload($node, $line),
                        'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open' => $this->auditor->addResult(
                            pattern: 'command_execution',
                            line: $line,
                            description: \sprintf('Use of %s() — command execution', $functionName),
                            severity: Severity::Warning,
                        ),
                        'file_get_contents', 'fwrite', 'fputs' => $this->checkFileOperation($node, $functionName, $line),
                        default => null,
                    };
                }

                if ($node instanceof Include_) {
                    $this->auditor->addResult(
                        pattern: 'dynamic_include',
                        line: $node->getStartLine(),
                        description: 'Dynamic file inclusion — possible LFI/RFI',
                        severity: Severity::Warning,
                    );
                }
            }

            private function checkEncodedPayload(FuncCall $node, int $line): void
            {
                if (\count($node->getArgs()) === 0) {
                    return;
                }

                $firstArg = $node->getArgs()[0]->value;

                if ($firstArg instanceof String_ && $this->isPotentialPayload($firstArg->value)) {
                    $this->auditor->addResult(
                        pattern: 'encoded_payload',
                        line: $line,
                        description: 'base64_decode with encoded payload — possible obfuscation',
                        severity: Severity::Critical,
                    );
                }
            }

            private function isPotentialPayload(string $value): bool
            {
                $decoded = \base64_decode($value, true);

                if ($decoded === false) {
                    return false;
                }

                return str_contains($decoded, 'eval')
                    || str_contains($decoded, 'exec')
                    || str_contains($decoded, 'system')
                    || str_contains($decoded, 'popen')
                    || str_contains($decoded, 'curl_exec');
            }

            private function checkFileOperation(FuncCall $node, string $functionName, int $line): void
            {
                foreach ($node->getArgs() as $arg) {
                    if ($arg->value instanceof String_) {
                        $value = $arg->value->value;

                        if ($this->isBinarySuspicious($value)) {
                            $this->auditor->addResult(
                                pattern: 'binary_blob',
                                line: $line,
                                description: \sprintf(
                                    '%s() with binary-like content — possible binary blob injection',
                                    $functionName,
                                ),
                                severity: Severity::Critical,
                            );
                        }
                    }
                }
            }

            private function isBinarySuspicious(string $value): bool
            {
                for ($i = 0; $i < \strlen($value); $i++) {
                    $ord = \ord($value[$i]);

                    if ($ord === 0) {
                        return true;
                    }
                }

                return false;
            }
        });

        try {
            $stmts = $parser->parse($contents);
        } catch (\PhpParser\Error) {
            return [];
        }

        $traverser->traverse($stmts);

        return $this->results();
    }

    public function addResult(
        string $pattern,
        int $line,
        string $description,
        Severity $severity = Severity::Warning,
    ): void {
        $this->results[] = new AuditResult(
            package: '',
            version: '',
            file: $this->currentFile,
            line: $line,
            pattern: $pattern,
            description: $description,
            severity: $severity,
        );
    }

    /**
     * @return list<AuditResult>
     */
    public function results(): array
    {
        return $this->results;
    }
}
