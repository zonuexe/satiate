<?php

declare(strict_types=1);

namespace Satiate\Audit;

use PhpParser\Node;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

final class Auditor
{
    /**
     * Composer events that run automatically during `composer install`/`update`/`create-project`,
     * i.e. without the user explicitly invoking a script. A shell command wired to one of these is
     * the canonical install-time supply-chain vector.
     *
     * @var list<string>
     */
    private const COMPOSER_INSTALL_EVENTS = [
        'pre-install-cmd', 'post-install-cmd', 'pre-update-cmd', 'post-update-cmd',
        'pre-autoload-dump', 'post-autoload-dump', 'post-root-package-install', 'post-create-project-cmd',
    ];

    /**
     * @var list<AuditResult>
     */
    private array $results = [];

    private string $currentFile = '';

    private string $currentPackage = '';

    private string $currentVersion = '';

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
        $this->currentPackage = $package;
        $this->currentVersion = $version;

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
        $traverser = new NodeTraverser();

        $traverser->addVisitor(new class($this) extends NodeVisitorAbstract {
            /**
             * Functions that accept a filename and therefore honour PHP stream wrappers — they can
             * read or write http://, ftp://, php://, data://, phar:// … not just local files.
             *
             * @var list<string>
             */
            private const PROTOCOL_WRAPPER_FUNCTIONS = [
                'file_get_contents', 'file_put_contents', 'fopen', 'copy', 'file', 'readfile',
            ];

            /**
             * Wrapper/remote schemes whose presence as a literal argument is a strong signal of a
             * remote fetch, data exfiltration, or wrapper-based code execution.
             *
             * php:// is deliberately excluded: its benign streams (php://memory, php://stdout,
             * php://input as an STDIN fallback, …) are ubiquitous in legitimate code, and the
             * dangerous php://filter chains are almost always built dynamically — which the
             * literal-argument check below cannot see anyway. php:// still surfaces at Info.
             *
             * @var list<string>
             */
            private const SUSPICIOUS_URL_SCHEMES = [
                'http://', 'https://', 'ftp://', 'ftps://', 'ssh2://',
                'data://', 'phar://', 'expect://',
            ];

            /**
             * Payload-unpacking decoders. Nesting them — or feeding their result to eval() — is the
             * canonical obfuscated-webshell shape (e.g. eval(gzinflate(base64_decode('…')))).
             *
             * @var list<string>
             */
            private const DECODER_FUNCTIONS = [
                'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13',
                'convert_uudecode', 'hex2bin',
            ];

            /**
             * Superglobals carrying attacker-controlled request input. $_SERVER is intentionally
             * omitted: it mixes attacker headers (HTTP_*) with benign server values (DOCUMENT_ROOT,
             * SCRIPT_NAME) that legitimately feed include paths, which would cause false positives.
             *
             * @var list<string>
             */
            private const REQUEST_SOURCES = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_FILES'];

            /**
             * Functions that return attacker-controlled request data.
             *
             * @var list<string>
             */
            private const REQUEST_SOURCE_FUNCTIONS = ['getallheaders', 'apache_request_headers'];

            /**
             * Sinks for which receiving request input directly is essentially never legitimate in
             * package source — a request-controlled value reaching one of these is a backdoor.
             *
             * @var list<string>
             */
            private const CODE_EXEC_SINKS = [
                'system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec',
                'assert', 'create_function', 'unserialize', 'preg_replace',
                'call_user_func', 'call_user_func_array', 'extract',
            ];

            /**
             * FFI entry points that bring native C code into the process — a classic
             * disable_functions / sandbox bypass.
             *
             * @var list<string>
             */
            private const FFI_ENTRY_METHODS = ['cdef', 'load', 'scope'];

            /**
             * Security-relevant php.ini settings with no legitimate reason to be touched from
             * package code via ini_set() — disabling hardening or wiring auto-prepend implants.
             *
             * @var list<string>
             */
            private const DANGEROUS_INI_KEYS = [
                'disable_functions', 'disable_classes', 'allow_url_include', 'allow_url_fopen',
                'open_basedir', 'auto_prepend_file', 'auto_append_file', 'extension_dir',
            ];

            /**
             * Functions that open an outbound network connection — a data-exfiltration / C2 channel.
             * Common in legitimate HTTP clients and mailers, so reported at Info as a capability.
             *
             * @var list<string>
             */
            private const NETWORK_FUNCTIONS = [
                'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen',
                'stream_socket_client', 'socket_connect', 'mail', 'mb_send_mail',
            ];

            /**
             * Host/URL fragments that are essentially never legitimate in package source — known
             * exfiltration / command-and-control infrastructure. A literal containing one is Critical.
             *
             * @var list<string>
             */
            private const SUSPICIOUS_HOST_PATTERNS = [
                'discord.com/api/webhooks', 'discordapp.com/api/webhooks', 'api.telegram.org',
                'pastebin.com/raw', 'webhook.site', 'requestbin', 'transfer.sh', '.onion',
            ];

            /**
             * System paths whose appearance as a literal is almost always reconnaissance — reading
             * the user database or process environment. Kept tight to avoid flagging legitimate
             * system-integration libraries (so e.g. ~/.ssh and ~/.aws are deliberately excluded).
             *
             * @var list<string>
             */
            private const SENSITIVE_PATH_PATTERNS = ['/etc/passwd', '/etc/shadow', '/proc/self/environ'];

            private readonly NodeFinder $finder;

            public function __construct(
                private readonly Auditor $auditor,
            ) {
                $this->finder = new NodeFinder();
            }

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Eval_) {
                    $this->auditor->addResult(
                        pattern: 'eval',
                        line: $node->getStartLine(),
                        description: 'Use of eval() detected — possible code injection',
                        severity: Severity::Critical,
                    );

                    // eval() of a decoded payload (eval(base64_decode(...)) / eval(gzinflate(...)))
                    // is the canonical obfuscated webshell — a much stronger signal than eval alone.
                    if ($this->containsDecoderCall($node->expr)) {
                        $this->auditor->addResult(
                            pattern: 'eval_decoded_payload',
                            line: $node->getStartLine(),
                            description: 'eval() of a decoded payload — obfuscated webshell signature',
                            severity: Severity::Critical,
                        );
                    }

                    if ($this->containsRequestSource($node->expr)) {
                        $this->reportRequestToSink('eval', $node->getStartLine());
                    }
                }

                if ($node instanceof Node\Expr\StaticCall
                    && $node->class instanceof Node\Name
                    && strtolower($node->class->toString()) === 'ffi'
                    && $node->name instanceof Node\Identifier
                    && \in_array(strtolower($node->name->name), self::FFI_ENTRY_METHODS, true)
                ) {
                    $this->auditor->addResult(
                        pattern: 'ffi_usage',
                        line: $node->getStartLine(),
                        description: \sprintf('FFI::%s() — loads/defines native C code (disable_functions / sandbox bypass)', $node->name->name),
                        severity: Severity::Warning,
                    );
                }

                if ($node instanceof FuncCall && $node->name instanceof Node\Name && ! $node->isFirstClassCallable()) {
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
                        'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec' => $this->auditor->addResult(
                            pattern: 'command_execution',
                            line: $line,
                            description: \sprintf('Use of %s() — process execution', $functionName),
                            severity: Severity::Warning,
                        ),
                        'file_get_contents', 'fwrite', 'fputs' => $this->checkFileOperation($node, $functionName, $line),
                        default => null,
                    };

                    // file_get_contents also goes through the binary-blob check above, so the
                    // wrapper-capable functions are inspected separately rather than in the match.
                    if (\in_array($functionName, self::PROTOCOL_WRAPPER_FUNCTIONS, true)) {
                        $this->checkProtocolWrapper($node, $functionName, $line);
                    }

                    if (\in_array($functionName, self::DECODER_FUNCTIONS, true)) {
                        $this->checkDecoderChain($node, $line);
                    }

                    if (\in_array($functionName, self::CODE_EXEC_SINKS, true)) {
                        foreach ($node->getArgs() as $arg) {
                            if ($this->containsRequestSource($arg->value)) {
                                $this->reportRequestToSink($functionName, $line);

                                break;
                            }
                        }
                    }

                    if ($functionName === 'dl') {
                        $this->auditor->addResult(
                            pattern: 'runtime_extension_load',
                            line: $line,
                            description: 'dl() loads a PHP extension at runtime — possible native-code execution',
                            severity: Severity::Warning,
                        );
                    }

                    if ($functionName === 'ini_set' || $functionName === 'ini_alter') {
                        $this->checkIniTampering($node, $line);
                    }

                    if (\in_array($functionName, self::NETWORK_FUNCTIONS, true)) {
                        $this->auditor->addResult(
                            pattern: 'network_call',
                            line: $line,
                            description: \sprintf('%s() opens an outbound network connection — review for data egress', $functionName),
                            severity: Severity::Info,
                        );
                    }

                    if ($functionName === 'assert') {
                        $assertArgs = $node->getArgs();

                        if ($assertArgs !== [] && $assertArgs[0]->value instanceof String_) {
                            $this->auditor->addResult(
                                pattern: 'assert_eval',
                                line: $line,
                                description: 'assert() with a string argument — evaluated as code on PHP < 8',
                                severity: Severity::Critical,
                            );
                        }
                    }
                }

                if ($node instanceof String_) {
                    $host = $this->matchedSuspiciousHost($node->value);

                    if ($host !== null) {
                        $this->auditor->addResult(
                            pattern: 'suspicious_host',
                            line: $node->getStartLine(),
                            description: \sprintf('Literal references known exfiltration/C2 infrastructure: %s', $host),
                            severity: Severity::Critical,
                        );
                    }

                    $path = $this->matchedSensitivePath($node->value);

                    if ($path !== null) {
                        $this->auditor->addResult(
                            pattern: 'sensitive_path',
                            line: $node->getStartLine(),
                            description: \sprintf('Literal references a sensitive system path (%s) — possible reconnaissance', $path),
                            severity: Severity::Warning,
                        );
                    }
                }

                if ($node instanceof Include_) {
                    $this->auditor->addResult(
                        pattern: 'dynamic_include',
                        line: $node->getStartLine(),
                        description: 'Dynamic file inclusion — possible LFI/RFI',
                        severity: Severity::Warning,
                    );

                    if ($this->containsRequestSource($node->expr)) {
                        $this->reportRequestToSink('include/require', $node->getStartLine());
                    }
                }

                return null;
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

            private function checkProtocolWrapper(FuncCall $node, string $functionName, int $line): void
            {
                // Strong signal: a literal argument that names a remote or stream wrapper. This is a
                // common remote-fetch / exfiltration / wrapper-RCE shape, so flag it critically.
                foreach ($node->getArgs() as $arg) {
                    if ($arg->value instanceof String_ && $this->hasSuspiciousScheme($arg->value->value)) {
                        $this->auditor->addResult(
                            pattern: 'protocol_wrapper',
                            line: $line,
                            description: \sprintf(
                                '%s() with a remote/stream-wrapper URL — possible remote fetch or wrapper abuse',
                                $functionName,
                            ),
                            severity: Severity::Critical,
                        );

                        return;
                    }
                }

                // Weak signal: the function honours stream wrappers at all. Common in legitimate
                // code, so reported at Info as a capability worth reviewing in package source.
                $this->auditor->addResult(
                    pattern: 'protocol_wrapper',
                    line: $line,
                    description: \sprintf(
                        '%s() supports protocol wrappers (http://, php://, phar:// …) — review for remote/wrapper use',
                        $functionName,
                    ),
                    severity: Severity::Info,
                );
            }

            private function matchedSuspiciousHost(string $value): ?string
            {
                $lower = strtolower($value);

                foreach (self::SUSPICIOUS_HOST_PATTERNS as $pattern) {
                    if (str_contains($lower, $pattern)) {
                        return $pattern;
                    }
                }

                return null;
            }

            private function matchedSensitivePath(string $value): ?string
            {
                foreach (self::SENSITIVE_PATH_PATTERNS as $pattern) {
                    if (str_contains($value, $pattern)) {
                        return $pattern;
                    }
                }

                return null;
            }

            private function hasSuspiciousScheme(string $value): bool
            {
                $lower = strtolower($value);

                foreach (self::SUSPICIOUS_URL_SCHEMES as $scheme) {
                    if (str_starts_with($lower, $scheme)) {
                        return true;
                    }
                }

                return false;
            }

            private function checkDecoderChain(FuncCall $node, int $line): void
            {
                // A decoder whose argument already contains another decoder (gzinflate(base64_decode
                // (...))) is layered obfuscation — almost never legitimate in package source.
                foreach ($node->getArgs() as $arg) {
                    if ($this->containsDecoderCall($arg->value)) {
                        $this->auditor->addResult(
                            pattern: 'decoder_chain',
                            line: $line,
                            description: 'Nested decoder calls (e.g. gzinflate(base64_decode(...))) — obfuscated payload',
                            severity: Severity::Warning,
                        );

                        return;
                    }
                }
            }

            private function containsDecoderCall(Node $node): bool
            {
                foreach ($this->finder->findInstanceOf($node, FuncCall::class) as $call) {
                    if ($call->name instanceof Node\Name
                        && \in_array($call->name->toLowerString(), self::DECODER_FUNCTIONS, true)
                    ) {
                        return true;
                    }
                }

                return false;
            }

            private function checkIniTampering(FuncCall $node, int $line): void
            {
                $args = $node->getArgs();

                if ($args === []) {
                    return;
                }

                $key = $args[0]->value;

                if ($key instanceof String_ && \in_array(strtolower($key->value), self::DANGEROUS_INI_KEYS, true)) {
                    $this->auditor->addResult(
                        pattern: 'ini_tampering',
                        line: $line,
                        description: \sprintf('ini_set("%s", ...) — tampering with a security-relevant php.ini setting', $key->value),
                        severity: Severity::Warning,
                    );
                }
            }

            private function reportRequestToSink(string $sink, int $line): void
            {
                $this->auditor->addResult(
                    pattern: 'request_to_sink',
                    line: $line,
                    description: \sprintf('%s() receives request input directly — likely backdoor', $sink),
                    severity: Severity::Critical,
                );
            }

            private function containsRequestSource(Node $node): bool
            {
                $found = $this->finder->findFirst($node, function (Node $candidate): bool {
                    if ($candidate instanceof Node\Expr\Variable && \is_string($candidate->name)) {
                        return \in_array($candidate->name, self::REQUEST_SOURCES, true);
                    }

                    if ($candidate instanceof FuncCall && $candidate->name instanceof Node\Name) {
                        return \in_array($candidate->name->toLowerString(), self::REQUEST_SOURCE_FUNCTIONS, true);
                    }

                    if ($candidate instanceof String_) {
                        return strtolower($candidate->value) === 'php://input';
                    }

                    return false;
                });

                return $found !== null;
            }
        });

        try {
            $stmts = $parser->parse($contents);
        } catch (\PhpParser\Error) {
            return [];
        }

        if ($stmts !== null) {
            $traverser->traverse($stmts);
            $this->detectMixedDeclarationAndSideEffects($stmts);
        }

        return $this->results();
    }

    /**
     * Flag a file that BOTH declares symbols and runs a top-level side effect (a PSR-1 violation).
     *
     * PSR-1: a file should either declare classes/functions/constants OR execute logic with side
     * effects, never both. A class/interface/trait/enum file that suddenly gains a top-level side
     * effect — a stray function call, an include, output — is a classic supply-chain injection
     * signature, so it is surfaced. Severity is Info: the same pattern is produced by benign code
     * (e.g. a trailing class_alias() or trigger_deprecation()), so this is a hint for review, not a
     * hard finding.
     *
     * @param array<Node\Stmt> $stmts
     */
    private function detectMixedDeclarationAndSideEffects(array $stmts): void
    {
        // Descend one level into namespace blocks (both "namespace Foo;" and "namespace Foo {}"
        // hold the real top-level statements) so namespaced declarations are seen.
        $topLevel = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    $topLevel[] = $inner;
                }
            } else {
                $topLevel[] = $stmt;
            }
        }

        $hasDeclaration = false;
        $sideEffectLines = [];

        foreach ($topLevel as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassLike || $stmt instanceof Node\Stmt\Function_) {
                $hasDeclaration = true;
            } elseif (! $this->isNeutralTopLevelStatement($stmt)) {
                $sideEffectLines[] = $stmt->getStartLine();
            }
        }

        if (! $hasDeclaration || $sideEffectLines === []) {
            return;
        }

        foreach ($sideEffectLines as $line) {
            $this->addResult(
                pattern: 'psr1_side_effect',
                line: $line,
                description: 'File declares symbols and has a top-level side effect (PSR-1 violation) — possible injected code',
                severity: Severity::Info,
            );
        }
    }

    /**
     * Statements that declare/import symbols or are otherwise inert at file load: they do not count
     * as side effects, so they may sit alongside class/function declarations without violating PSR-1.
     */
    private function isNeutralTopLevelStatement(Node\Stmt $stmt): bool
    {
        return $stmt instanceof Node\Stmt\Declare_
            || $stmt instanceof Node\Stmt\Use_
            || $stmt instanceof Node\Stmt\GroupUse
            || $stmt instanceof Node\Stmt\Const_
            || $stmt instanceof Node\Stmt\Nop;
    }

    /**
     * Archive suffixes satiate can audit: the formats `satiate build` can emit (`archive.format`
     * is one of zip, tar, tar.gz, tar.bz2). The filename suffix — not just the last extension —
     * is matched so compound suffixes like ".tar.gz" are recognised.
     *
     * @var list<string>
     */
    private const ARCHIVE_SUFFIXES = ['.zip', '.tar', '.tar.gz', '.tar.bz2'];

    public static function isSupportedArchive(string $path): bool
    {
        $lower = strtolower($path);

        foreach (self::ARCHIVE_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Audit every PHP file inside a distribution archive.
     *
     * A built mirror stores package code as zip or tar archives under dist/, not as loose .php
     * files, so auditing a mirror means looking inside those archives. The archive is extracted to
     * a temporary directory, each PHP file is audited, and the reported path is rewritten to
     * "<archive>/<internal/path.php>" so a finding can be located without the temp directory
     * leaking into the output. The temporary directory is always removed.
     *
     * @return list<AuditResult>
     */
    public function auditArchive(string $package, string $version, string $archivePath): array
    {
        if (! is_file($archivePath)) {
            return [];
        }

        $tmpDir = sys_get_temp_dir() . '/satiate_audit_' . bin2hex(random_bytes(6));

        if (! mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
            return [];
        }

        try {
            if (! $this->extractArchive($archivePath, $tmpDir)) {
                return [];
            }

            $archiveLabel = basename($archivePath);
            $results = [];

            foreach ($this->phpFilesIn($tmpDir) as $phpFile) {
                $internalPath = substr($phpFile, \strlen($tmpDir) + 1);

                foreach ($this->auditFile($package, $version, $phpFile) as $result) {
                    $results[] = $this->relabel($result, $archiveLabel . '/' . $internalPath);
                }
            }

            // The package's root manifest is the install-time attack surface (scripts, plugin type).
            $composerJson = $tmpDir . '/composer.json';

            if (is_file($composerJson)) {
                foreach ($this->auditComposerJson($package, $version, $composerJson) as $result) {
                    $results[] = $this->relabel($result, $archiveLabel . '/composer.json');
                }
            }

            return $results;
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    /**
     * Audit a package's composer.json for install-time supply-chain surface: shell commands wired
     * to Composer's auto-run install/update hooks, the composer-plugin type (arbitrary code during
     * Composer operations), and autoload.files (code executed on every autoload).
     *
     * @return list<AuditResult>
     */
    public function auditComposerJson(string $package, string $version, string $filePath): array
    {
        if (! is_file($filePath)) {
            return [];
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        $this->results = [];
        $this->currentFile = $filePath;
        $this->currentPackage = $package;
        $this->currentVersion = $version;

        if (($decoded['type'] ?? null) === 'composer-plugin') {
            $this->addResult(
                pattern: 'composer_plugin',
                line: 0,
                description: 'Package type is composer-plugin — runs arbitrary code during Composer operations',
                severity: Severity::Warning,
            );
        }

        $scripts = $decoded['scripts'] ?? null;

        if (is_array($scripts)) {
            foreach ($scripts as $event => $commands) {
                if (! is_string($event) || ! \in_array($event, self::COMPOSER_INSTALL_EVENTS, true)) {
                    continue;
                }

                $commandList = is_array($commands) ? $commands : [$commands];

                foreach ($commandList as $command) {
                    if (! is_string($command)) {
                        continue;
                    }

                    if ($this->isShellScript($command)) {
                        $this->addResult(
                            pattern: 'composer_install_hook',
                            line: 0,
                            description: \sprintf('Composer "%s" runs a shell command on install: %s', $event, $this->truncate($command)),
                            severity: Severity::Critical,
                        );
                    } elseif ($this->isInlinePhp($command)) {
                        $this->addResult(
                            pattern: 'composer_install_hook',
                            line: 0,
                            description: \sprintf('Composer "%s" runs inline PHP (@php -r) on install: %s', $event, $this->truncate($command)),
                            severity: Severity::Critical,
                        );
                    }
                }
            }
        }

        $autoload = $decoded['autoload'] ?? null;
        $autoloadFiles = is_array($autoload) ? ($autoload['files'] ?? null) : null;

        if (is_array($autoloadFiles) && $autoloadFiles !== []) {
            $this->addResult(
                pattern: 'autoload_files',
                line: 0,
                description: 'autoload.files entries are executed on every autoload — a side-effect injection point',
                severity: Severity::Info,
            );
        }

        return $this->results();
    }

    /**
     * A Composer script entry is a raw shell command unless it is a Class::method PHP callback or a
     * Composer script reference (@php, @composer, @putenv, @other-script).
     */
    private function isShellScript(string $command): bool
    {
        $trimmed = ltrim($command);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) === 1) {
            return false;
        }

        return ! str_starts_with($trimmed, '@');
    }

    /**
     * `@php` invocations are normally benign (running a script file), but `@php -r '<code>'`
     * executes inline PHP at install time — an arbitrary-code-execution hook.
     */
    private function isInlinePhp(string $command): bool
    {
        $trimmed = ltrim($command);

        return preg_match('/^@php(\s|$)/i', $trimmed) === 1
            && preg_match('/(^|\s)-r(\s|$)/', $trimmed) === 1;
    }

    private function truncate(string $value): string
    {
        $value = trim($value);

        return \strlen($value) > 80 ? substr($value, 0, 77) . '...' : $value;
    }

    private function relabel(AuditResult $result, string $file): AuditResult
    {
        return new AuditResult(
            package: $result->package,
            version: $result->version,
            file: $file,
            line: $result->line,
            pattern: $result->pattern,
            description: $result->description,
            severity: $result->severity,
        );
    }

    private function extractArchive(string $archivePath, string $tmpDir): bool
    {
        $lower = strtolower($archivePath);

        if (str_ends_with($lower, '.zip')) {
            $zip = new \ZipArchive();

            if ($zip->open($archivePath) !== true) {
                return false;
            }

            $zip->extractTo($tmpDir);
            $zip->close();

            return true;
        }

        // tar, tar.gz, tar.bz2 — PharData reads the compression transparently from the extension.
        foreach (['.tar', '.tar.gz', '.tar.bz2'] as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                try {
                    (new \PharData($archivePath))->extractTo($tmpDir, null, true);

                    return true;
                } catch (\Exception) {
                    return false;
                }
            }
        }

        return false;
    }

    public function addResult(
        string $pattern,
        int $line,
        string $description,
        Severity $severity = Severity::Warning,
    ): void {
        $this->results[] = new AuditResult(
            package: $this->currentPackage,
            version: $this->currentVersion,
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

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function removeDir(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
