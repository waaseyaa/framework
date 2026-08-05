<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TestQualityInventoryTest extends TestCase
{
    private string $repoRoot = '';

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function it_reports_a_git_aware_machine_readable_inventory(): void
    {
        $inventory = $this->runInventory();

        self::assertSame(1, $inventory['schema_version']);
        self::assertGreaterThan(1_000, $inventory['php']['test_files']);
        self::assertGreaterThan(5_000, $inventory['php']['test_methods']);
        self::assertGreaterThan(10_000, $inventory['php']['assertion_calls']);
        self::assertSame(20, $inventory['phpunit']['config_files']);
        self::assertGreaterThan(50, $inventory['admin']['vitest_files']);
        self::assertGreaterThan(10, $inventory['admin']['playwright_files']);
        self::assertSame([], $inventory['determinism']['waits']['unclassified']);
        self::assertSame([], $inventory['determinism']['conditional_skips']['framework_gap']);
        self::assertSame([], $inventory['determinism']['conditional_skips']['unclassified']);
        self::assertCount(3, array_merge(
            $inventory['determinism']['waits']['subprocess_polling'],
            $inventory['determinism']['waits']['filesystem_retry'],
        ));
    }

    #[Test]
    public function it_excludes_untracked_test_shaped_files(): void
    {
        $before = $this->runInventory();
        $probe = $this->repoRoot . '/tests/UntrackedInventoryProbeTest.php';
        self::assertFileDoesNotExist($probe);

        file_put_contents($probe, <<<'PHP'
            <?php
            final class UntrackedInventoryProbeTest
            {
                public function test_probe(): void {}
            }
            PHP);

        try {
            $after = $this->runInventory();
        } finally {
            unlink($probe);
        }

        self::assertSame($before, $after);
    }

    /** @return array<string, mixed> */
    private function runInventory(): array
    {
        $output = [];
        $exitCode = 0;
        exec(
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($this->repoRoot . '/bin/test-quality-inventory')
            . ' --format=json 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));

        $decoded = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
