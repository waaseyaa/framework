<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorPrincipal;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\RecordingLogger;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\CLI\Command\Mcp\McpServeCommand;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Tests\Support\RecordingStrictAuditLedger;
use Waaseyaa\Foundation\Audit\NullStrictAuditLedger;

/**
 * Wiring-level tests for {@see McpServeCommand}: local-operator construction,
 * allowlist narrowing, audit-dispatcher refusal, and — the ADR-022 D-9.2
 * acceptance criterion this test class exists to prove — that EVERY refusal
 * path exits non-zero with a structured diagnostic on stderr and writes
 * NOTHING to stdout. Protocol-level JSON-RPC behaviour is
 * {@see \Waaseyaa\CLI\Tests\Unit\Mcp\Stdio\StdioMcpServerTest}'s job; the real
 * subprocess round trip is `tests/Integration/Mcp/StdioMcpConformanceTest.php`.
 */
#[CoversClass(McpServeCommand::class)]
final class McpServeCommandTest extends TestCase
{
    private const array DEV_CONFIG = ['environment' => 'local'];
    private const array PRODUCTION_CONFIG = ['environment' => 'production'];

    #[Test]
    public function an_unsupported_profile_refuses_before_any_local_operator_construction(): void
    {
        [$io, $stdout, $stderr] = $this->io(['--profile' => 'yolo']);
        $out = fopen('php://memory', 'r+');

        $command = new McpServeCommand(
            toolRegistry: $this->registry([]),
            auditLedger: new RecordingStrictAuditLedger(),
            runtimeConfig: self::DEV_CONFIG,
            serverVersion: '1.0.0',
            in: $this->emptyStream(),
            out: $out,
        );

        self::assertSame(1, $command->execute($io));
        self::assertSame('', $this->contents($out), 'A refused startup must write nothing to stdout.');

        $diagnostic = json_decode($stderr->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('unsupported_profile', $diagnostic['error']);
    }

    #[Test]
    public function a_production_shaped_runtime_refuses_via_local_operator_refusal_and_writes_nothing_to_stdout(): void
    {
        [$io, , $stderr] = $this->io();
        $out = fopen('php://memory', 'r+');

        $command = new McpServeCommand(
            toolRegistry: $this->registry([]),
            auditLedger: new RecordingStrictAuditLedger(),
            runtimeConfig: self::PRODUCTION_CONFIG,
            serverVersion: '1.0.0',
            in: $this->emptyStream(),
            out: $out,
        );

        self::assertSame(1, $command->execute($io));
        self::assertSame('', $this->contents($out));

        $diagnostic = json_decode($stderr->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('local_operator_refused', $diagnostic['error']);
        self::assertSame('R-6', $diagnostic['row']);
    }

    #[Test]
    public function a_null_audit_ledger_refuses_at_startup_before_the_read_loop_ever_runs(): void
    {
        [$io, , $stderr] = $this->io();
        $out = fopen('php://memory', 'r+');
        // A stream that WOULD supply a request if the loop ever read from it —
        // proving the refusal happens before any read, not merely before any write.
        $in = fopen('php://memory', 'r+');
        fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], JSON_THROW_ON_ERROR) . "\n");
        rewind($in);

        $command = new McpServeCommand(
            toolRegistry: $this->registry([]),
            auditLedger: new NullStrictAuditLedger(),
            runtimeConfig: self::DEV_CONFIG,
            serverVersion: '1.0.0',
            in: $in,
            out: $out,
        );

        self::assertSame(1, $command->execute($io));
        self::assertSame('', $this->contents($out));

        $diagnostic = json_decode($stderr->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('audit_dispatcher_refused', $diagnostic['error']);
    }

    #[Test]
    public function the_allowlist_narrows_tools_list_to_only_the_default_profiles_admitted_tool_ids(): void
    {
        [$io] = $this->io();
        $out = fopen('php://memory', 'r+');
        $in = fopen('php://memory', 'r+');
        fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR) . "\n");
        rewind($in);

        $command = new McpServeCommand(
            toolRegistry: $this->registry([
                $this->fixtureTool('bimaaji_search_specs', 'bimaaji.read'),
                $this->fixtureTool('not_on_the_default_allowlist', 'bimaaji.read'),
            ]),
            auditLedger: new RecordingStrictAuditLedger(),
            runtimeConfig: self::DEV_CONFIG,
            serverVersion: '1.0.0',
            in: $in,
            out: $out,
        );

        self::assertSame(0, $command->execute($io));

        $frame = json_decode($this->contents($out), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($frame['result']['tools'], 'name');
        self::assertSame(['bimaaji_search_specs'], $names, 'Only the D-7 allowlisted tool id may surface, even though the registry holds two.');
    }

    #[Test]
    public function a_tools_call_reserves_and_finalizes_with_the_dedicated_surface_a_null_actor_uid_and_the_principals_metadata(): void
    {
        [$io] = $this->io();
        $out = fopen('php://memory', 'r+');
        $in = fopen('php://memory', 'r+');
        fwrite($in, json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'bimaaji_search_specs', 'arguments' => ['query' => 'entity']],
        ], JSON_THROW_ON_ERROR) . "\n");
        rewind($in);

        $ledger = new RecordingStrictAuditLedger();
        $command = new McpServeCommand(
            toolRegistry: $this->registry([$this->fixtureTool('bimaaji_search_specs', 'bimaaji.read')]),
            auditLedger: $ledger,
            runtimeConfig: self::DEV_CONFIG,
            serverVersion: '1.0.0',
            in: $in,
            out: $out,
        );

        self::assertSame(0, $command->execute($io));

        $frame = json_decode($this->contents($out), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $frame);

        $reserve = $ledger->calls[0];
        $finalize = $ledger->calls[1];
        self::assertSame('reserve', $reserve['type']);
        self::assertSame(McpServeCommand::AUDIT_SURFACE, $reserve['reservation']->surface);
        self::assertNotSame('mcp', $reserve['reservation']->surface);
        self::assertFalse(str_starts_with($reserve['reservation']->surface, 'mcp.'));
        self::assertNull($reserve['reservation']->actorUid, 'D-5.D.7 — never a coerced 0.');
        self::assertSame(LocalOperatorPrincipal::ID, $reserve['reservation']->metadata['principal']);
        self::assertSame('finalize', $finalize['type']);
        self::assertNotSame('', $reserve['reservation']->correlationId);
    }

    #[Test]
    public function an_escaping_tool_exception_uses_one_correlation_id_across_response_log_and_audit(): void
    {
        $this->assertFailureCorrelationIsJoined($this->fixtureTool(
            'bimaaji_search_specs',
            'bimaaji.read',
            static fn(array $arguments): AgentToolResult => throw new \RuntimeException('secret failure detail'),
        ));
    }

    #[Test]
    public function an_output_schema_failure_uses_one_correlation_id_across_response_log_and_audit(): void
    {
        $this->assertFailureCorrelationIsJoined($this->fixtureTool(
            'bimaaji_search_specs',
            'bimaaji.read',
            static fn(array $arguments): AgentToolResult => AgentToolResult::success(
                [['type' => 'text', 'text' => 'ok']],
                structuredContent: ['wrong' => true],
            ),
            ['type' => 'object', 'required' => ['count'], 'properties' => ['count' => ['type' => 'integer']]],
        ));
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: SymfonyCommandIO, 1: BufferedOutput, 2: BufferedOutput} */
    private function io(array $options = []): array
    {
        $definition = new InputDefinition([new InputOption('profile', mode: InputOption::VALUE_REQUIRED)]);
        $input = new ArrayInput($options, $definition);
        $stdout = new BufferedOutput();
        $stderr = new BufferedOutput();

        return [new SymfonyCommandIO($input, $stdout, $stderr), $stdout, $stderr];
    }

    /** @return resource */
    private function emptyStream()
    {
        return fopen('php://memory', 'r+');
    }

    /** @param resource $stream */
    private function contents($stream): string
    {
        rewind($stream);

        return trim((string) stream_get_contents($stream));
    }

    /** @param list<AgentTool> $tools */
    private function registry(array $tools): ToolRegistryInterface
    {
        return new class ($tools) implements ToolRegistryInterface {
            /** @param list<AgentTool> $tools */
            public function __construct(private array $tools) {}

            public function register(AgentTool $tool): void
            {
                $this->tools[] = $tool;
            }

            public function get(string $name): AgentTool
            {
                foreach ($this->tools as $tool) {
                    if ($tool->name === $name) {
                        return $tool;
                    }
                }

                throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                foreach ($this->tools as $tool) {
                    if ($tool->name === $name) {
                        return true;
                    }
                }

                return false;
            }

            public function all(): iterable
            {
                return $this->tools;
            }
        };
    }

    /**
     * @param ?\Closure(array<string, mixed>): AgentToolResult $handler
     * @param ?array<string, mixed> $outputSchema
     */
    private function fixtureTool(
        string $name,
        string $capability,
        ?\Closure $handler = null,
        ?array $outputSchema = null,
    ): AgentTool
    {
        $impl = new class ($handler) implements AgentToolInterface {
            /** @param ?\Closure(array<string, mixed>): AgentToolResult $handler */
            public function __construct(private readonly ?\Closure $handler) {}

            public function execute(array $arguments, \Waaseyaa\Access\AuthorizationPrincipalInterface $account): AgentToolResult
            {
                if ($this->handler !== null) {
                    return ($this->handler)($arguments);
                }

                return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
            }

            public function dryRun(array $arguments, \Waaseyaa\Access\AuthorizationPrincipalInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'additionalProperties' => false];
            }

            public function description(): string
            {
                return 'Fixture.';
            }
        };

        return new AgentTool(
            name: $name,
            capability: $capability,
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'additionalProperties' => false],
            impl: $impl,
            outputSchema: $outputSchema,
        );
    }

    private function assertFailureCorrelationIsJoined(AgentTool $tool): void
    {
        [$io] = $this->io();
        $out = fopen('php://memory', 'r+');
        $in = fopen('php://memory', 'r+');
        fwrite($in, json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool->name, 'arguments' => ['query' => 'entity']],
        ], JSON_THROW_ON_ERROR) . "\n");
        rewind($in);

        $ledger = new RecordingStrictAuditLedger();
        $logger = new RecordingLogger();
        $command = new McpServeCommand(
            toolRegistry: $this->registry([$tool]),
            auditLedger: $ledger,
            runtimeConfig: self::DEV_CONFIG,
            serverVersion: '1.0.0',
            logger: $logger,
            in: $in,
            out: $out,
        );

        self::assertSame(0, $command->execute($io));

        $frame = json_decode($this->contents($out), true, flags: JSON_THROW_ON_ERROR);
        $body = json_decode($frame['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $correlationId = $body['meta']['correlation_id'];
        self::assertNotSame('', $correlationId);
        self::assertSame($correlationId, $logger->withLevel('error')[0]['context']['correlation_id']);
        self::assertSame($correlationId, $ledger->calls[0]['reservation']->correlationId);
        self::assertSame($correlationId, $ledger->calls[1]['receipt']->correlationId);
        self::assertSame(\Waaseyaa\Foundation\Audit\AuditStage::ExecutionFailed, $ledger->calls[1]['stage']);
    }
}
