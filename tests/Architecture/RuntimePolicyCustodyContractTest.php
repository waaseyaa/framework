<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\Database\SqliteTopology;
use Waaseyaa\Foundation\Kernel\RuntimePolicy;

#[CoversNothing]
final class RuntimePolicyCustodyContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function productionRuntimePolicySitesMatchTheReviewedBaseline(): void
    {
        [$exit, $output] = $this->runCommand([$this->root . '/bin/check-runtime-policy-custody']);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('reviewed canonical custody', $output);
    }

    #[Test]
    public function directDebugReadAndIndependentClassifierAreRejected(): void
    {
        $fixture = sys_get_temp_dir() . '/waaseyaa-runtime-policy-' . bin2hex(random_bytes(8));
        $source = $fixture . '/packages/example/src';
        self::assertTrue(mkdir($source, 0o700, true));
        file_put_contents($source . '/Bypass.php', <<<'PHP'
            <?php

            $debug = getenv('APP_DEBUG');
            $development = in_array($environment, ['local'], true);
            PHP);

        try {
            [$exit, $output] = $this->runCommand([
                $this->root . '/bin/check-runtime-policy-custody',
                '--root',
                $fixture,
            ]);
            self::assertSame(1, $exit, $output);
            self::assertStringContainsString('RPC001', $output);
            self::assertStringContainsString('RPC002', $output);
            self::assertStringNotContainsString('APP_DEBUG', $output);
        } finally {
            @unlink($source . '/Bypass.php');
            @rmdir($source);
            @rmdir(dirname($source));
            @rmdir(dirname(dirname($source)));
            @rmdir($fixture . '/packages');
            @rmdir($fixture);
        }
    }

    #[Test]
    public function sqliteLayerExceptionMatchesTheCanonicalAllowlistExactly(): void
    {
        $canonical = new \ReflectionClass(RuntimePolicy::class)
            ->getReflectionConstant('DEVELOPMENT_ENVIRONMENTS')
            ?->getValue();
        $sqlite = SqliteTopology::MEMORY_ENVIRONMENTS;

        self::assertIsArray($canonical);
        sort($canonical);
        sort($sqlite);
        self::assertSame($canonical, $sqlite);
    }

    /** @param list<string> $command @return array{int, string} */
    private function runCommand(array $command): array
    {
        $process = new Process($command, $this->root, null, null, null);
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }
}
