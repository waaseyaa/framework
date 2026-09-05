<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Provider\MiscBServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

/**
 * #2832: sync-rules must read the rule set shipped by waaseyaa/foundation.
 *
 * A direct-package consumer intentionally has no vendor/waaseyaa/framework
 * directory. The provider used to derive its source from that absent aggregate,
 * so the real command exited 1 even though the CLI's required Foundation package
 * owned and shipped all three canonical rules.
 */
#[CoversClass(MiscBServiceProvider::class)]
final class MiscBSyncRulesCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_sync_rules_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700);
        file_put_contents($this->root . '/composer.json', "{}\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function sync_rules_dry_run_reads_canonical_foundation_rules_without_a_framework_package(): void
    {
        self::assertDirectoryDoesNotExist($this->root . '/vendor/waaseyaa/framework');
        self::assertDirectoryDoesNotExist($this->root . '/.claude/rules');

        $provider = new MiscBServiceProvider();
        $provider->setKernelContext($this->root, [], []);
        $tester = CliTester::for($this->syncRulesCommand($provider), $this->throwingContainer());

        $tester->executeMap(['--dry-run' => true]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertSame('', $tester->getStderr());
        self::assertSame(
            [
                'waaseyaa-data-freshness.md',
                'waaseyaa-framework.md',
                'waaseyaa-shell-compat.md',
            ],
            $this->reportedRuleFiles($tester->getStdout()),
        );
        self::assertStringContainsString('3 added, 0 updated, 0 skipped.', $tester->getStdout());
        self::assertDirectoryDoesNotExist($this->root . '/.claude/rules');
    }

    private function syncRulesCommand(MiscBServiceProvider $provider): HandlerCommand
    {
        foreach ($provider->consoleCommands() as $command) {
            if ($command instanceof HandlerCommand && $command->name === 'sync-rules') {
                return $command;
            }
        }

        self::fail('sync-rules command not found in MiscBServiceProvider::consoleCommands()');
    }

    /** @return list<string> */
    private function reportedRuleFiles(string $stdout): array
    {
        preg_match_all('/^\[dry run\] Would add: ([^\r\n]+)$/m', $stdout, $matches);

        return $matches[1];
    }

    private function throwingContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Container::get not used in this test');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
