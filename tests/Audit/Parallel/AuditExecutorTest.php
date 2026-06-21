<?php

declare(strict_types=1);

namespace Satiate\Tests\Audit\Parallel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Satiate\Audit\Parallel\AuditExecutor;
use Satiate\Audit\Parallel\AuditTarget;
use Satiate\Audit\Parallel\AuditTargetKind;

#[CoversClass(AuditExecutor::class)]
#[CoversClass(AuditTarget::class)]
#[CoversClass(AuditTargetKind::class)]
final class AuditExecutorTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo) {
                    continue;
                }

                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->tmp);
        }
    }

    /**
     * @return list<AuditTarget>
     */
    private function makeTargets(): array
    {
        $this->tmp = sys_get_temp_dir() . '/executor_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        file_put_contents($this->tmp . '/eval.php', '<?php eval($x);');
        file_put_contents($this->tmp . '/exec.php', '<?php exec($cmd);');
        file_put_contents($this->tmp . '/clean.php', '<?php echo "ok";');

        return [
            new AuditTarget($this->tmp . '/eval.php', AuditTargetKind::Php),
            new AuditTarget($this->tmp . '/exec.php', AuditTargetKind::Php),
            new AuditTarget($this->tmp . '/clean.php', AuditTargetKind::Php),
        ];
    }

    public function testSequentialKeysResultsByTargetPath(): void
    {
        $targets = $this->makeTargets();

        $results = (new AuditExecutor(1))->run($targets);

        self::assertSame(
            [$this->tmp . '/eval.php', $this->tmp . '/exec.php', $this->tmp . '/clean.php'],
            array_keys($results),
        );
        self::assertNotEmpty($results[$this->tmp . '/eval.php']);
        self::assertSame([], $results[$this->tmp . '/clean.php']);
    }

    public function testParallelMatchesSequentialResults(): void
    {
        $targets = $this->makeTargets();

        $sequential = (new AuditExecutor(1))->run($targets);
        $parallel = (new AuditExecutor(4))->run($targets);

        // Order-insensitive deep comparison: same findings per path, regardless of batch completion.
        self::assertEquals($sequential, $parallel);
    }

    public function testEmptyTargetsReturnEmpty(): void
    {
        self::assertSame([], (new AuditExecutor(1))->run([]));
        self::assertSame([], (new AuditExecutor(4))->run([]));
    }
}
