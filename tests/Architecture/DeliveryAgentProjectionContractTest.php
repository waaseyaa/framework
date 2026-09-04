<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class DeliveryAgentProjectionContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_credential_free_hostile_self_test_passes(): void
    {
        $result = $this->execute([PHP_BINARY, $this->root . '/bin/project-delivery-agent-events', '--self-test']);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('PASS', $result['stdout']);
    }

    #[Test]
    public function durable_operations_require_an_immutable_source_ref(): void
    {
        $result = $this->execute([PHP_BINARY, $this->root . '/bin/project-delivery-agent-events', 'plan']);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('--source-ref', $result['stderr']);
    }

    #[Test]
    public function unknown_options_fail_closed(): void
    {
        $result = $this->execute([PHP_BINARY, $this->root . '/bin/project-delivery-agent-events', '--unknown']);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('unknown', $result['stderr']);
    }

    #[Test]
    public function implementation_contains_the_exact_set_and_source_binding_controls(): void
    {
        $source = (string) file_get_contents($this->root . '/bin/project-delivery-agent-events');

        foreach ([
            'bin/git',
            'show',
            'source_commit_sha',
            'raw_event_json',
            'source_ordinal',
            'ledger_sha256',
            'schema_sha256',
            'rollBack',
            'GET_LOCK',
            'rewrites the append-only ledger prefix',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringNotContainsString('INSERT IGNORE', strtoupper($source));
    }

    #[Test]
    public function the_tracked_dashboard_uses_the_governed_projection_without_reinterpreting_pending_work(): void
    {
        $path = $this->root . '/ops/observability/grafana/waaseyaa-k1-delivery-flow.json';
        self::assertFileExists($path);
        $json = (string) file_get_contents($path);
        json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertStringContainsString('waaseyaa_delivery_projection_state', $json);
        self::assertStringContainsString('waaseyaa_delivery_agent_events_v1', $json);
        self::assertStringContainsString("'pending'", $json);
        self::assertStringNotContainsString("'unresolved'", $json);
        self::assertStringNotContainsString('waaseyaa_agent_events', $json);
        self::assertStringNotContainsString('devlake-spike', $json);
    }

    /** @param list<string> $command @return array{exit: int, stdout: string, stderr: string} */
    private function execute(array $command): array
    {
        $process = new Process($command, $this->root);
        $process->run();

        return ['exit' => $process->getExitCode() ?? 1, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }
}
