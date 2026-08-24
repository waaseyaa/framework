<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class GovernedSecretAccessContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function governed_integration_and_provider_packages_use_only_resolver_custody(): void
    {
        [$exit, $output] = $this->runCommand([$this->root . '/bin/check-governed-secret-access']);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('governed secret access', $output);
    }

    #[Test]
    public function direct_environment_read_is_rejected_in_a_synthetic_package_tree(): void
    {
        $fixture = sys_get_temp_dir() . '/waaseyaa-secret-boundary-' . bin2hex(random_bytes(8));
        $source = $fixture . '/packages/ai-agent/src/Provider';
        self::assertTrue(mkdir($source, 0o700, true));
        file_put_contents($source . '/Bypass.php', <<<'PHP'
            <?php

            $credential = \getenv('SYNTHETIC_PROVIDER_KEY');
            $document = \file_get_contents('/tmp/synthetic-public-document');
            PHP);

        try {
            [$exit, $output] = $this->runCommand([
                $this->root . '/bin/check-governed-secret-access',
                '--root',
                $fixture,
            ]);
            self::assertSame(1, $exit, $output);
            self::assertStringContainsString('GSA001', $output);
            self::assertStringContainsString('GSA002', $output);
            self::assertStringNotContainsString('SYNTHETIC_PROVIDER_KEY', $output);
        } finally {
            @unlink($source . '/Bypass.php');
            @rmdir($source);
            @rmdir(dirname($source));
            @rmdir(dirname(dirname($source)));
            @rmdir($fixture . '/packages');
            @rmdir($fixture);
        }
    }

    /**
     * @param list<string> $command
     * @return array{int, string}
     */
    private function runCommand(array $command): array
    {
        // proc_open() received no env argument, so the child inherited this
        // process's environment; Symfony reproduces that with a null $env.
        // timeout: null keeps the gate untimed, as it was (#2491).
        $process = new Process($command, $this->root, null, null, null);
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }
}
