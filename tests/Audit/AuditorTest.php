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
}
