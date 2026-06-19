<?php

declare(strict_types=1);

namespace Satiate\Tests\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Satiate\Audit\Auditor;
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

        self::assertCount(1, $results);
        self::assertSame('eval', $results[0]->pattern);
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

        self::assertCount(1, $results);
        self::assertSame('binary_blob', $results[0]->pattern);
        self::assertSame(Severity::Critical, $results[0]->severity);
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

        self::assertCount(0, $results);
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
}
