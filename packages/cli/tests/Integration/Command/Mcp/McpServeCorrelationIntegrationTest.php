<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration\Command\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\RecordingLogger;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Audit\Writer\DatabaseStrictAuditLedger;
use Waaseyaa\CLI\Command\Mcp\McpServeCommand;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * Proves the stdio transport's correlation contract against the durable SQLite
 * ledger, not only recording fakes: one identity joins the caller-visible
 * failure, safe operator log, reservation, and terminal audit row.
 */
#[CoversNothing]
final class McpServeCorrelationIntegrationTest extends TestCase
{
    #[Test]
    public function an_escaping_exception_is_joined_across_response_log_and_database_ledger(): void
    {
        $this->assertDurableCorrelation($this->tool(
            static fn(array $arguments): AgentToolResult => throw new \RuntimeException('secret infrastructure detail'),
        ));
    }

    #[Test]
    public function an_output_schema_failure_is_joined_across_response_log_and_database_ledger(): void
    {
        $this->assertDurableCorrelation($this->tool(
            static fn(array $arguments): AgentToolResult => AgentToolResult::success(
                [['type' => 'text', 'text' => 'ok']],
                structuredContent: ['wrong' => true],
            ),
            ['type' => 'object', 'required' => ['count'], 'properties' => ['count' => ['type' => 'integer']]],
        ));
    }

    private function assertDurableCorrelation(AgentTool $tool): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);

        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        fwrite($in, json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool->name, 'arguments' => ['query' => 'entity']],
        ], JSON_THROW_ON_ERROR) . "\n");
        rewind($in);

        $logger = new RecordingLogger();
        $command = new McpServeCommand(
            toolRegistry: $this->registry($tool),
            auditLedger: new DatabaseStrictAuditLedger($database),
            runtimeConfig: ['environment' => 'local'],
            serverVersion: 'integration-test',
            logger: $logger,
            in: $in,
            out: $out,
        );

        $definition = new InputDefinition([new InputOption('profile', mode: InputOption::VALUE_REQUIRED)]);
        $io = new SymfonyCommandIO(
            new ArrayInput([], $definition),
            new BufferedOutput(),
            new BufferedOutput(),
        );

        self::assertSame(0, $command->execute($io));
        rewind($out);
        $frame = json_decode(trim((string) stream_get_contents($out)), true, flags: JSON_THROW_ON_ERROR);
        $failure = json_decode($frame['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $correlationId = $failure['meta']['correlation_id'];

        $rows = array_map(
            static fn(object|array $row): array => (array) $row,
            iterator_to_array($database->query(
                'SELECT correlation_id, event_type, surface, operation, stage '
                . 'FROM strict_audit_ledger ORDER BY id',
            )),
        );

        self::assertNotSame('', $correlationId);
        self::assertCount(2, $rows);
        self::assertSame(['reserved', 'finalized'], array_column($rows, 'event_type'));
        self::assertSame([$correlationId, $correlationId], array_column($rows, 'correlation_id'));
        self::assertSame([McpServeCommand::AUDIT_SURFACE, McpServeCommand::AUDIT_SURFACE], array_column($rows, 'surface'));
        self::assertSame([$tool->name, $tool->name], array_column($rows, 'operation'));
        self::assertSame(AuditStage::ExecutionFailed->value, $rows[1]['stage']);
        self::assertSame($correlationId, $logger->withLevel('error')[0]['context']['correlation_id']);
    }

    /**
     * @param \Closure(array<string, mixed>): AgentToolResult $handler
     * @param ?array<string, mixed> $outputSchema
     */
    private function tool(\Closure $handler, ?array $outputSchema = null): AgentTool
    {
        $schema = [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $impl = new class ($handler, $schema) implements AgentToolInterface {
            /**
             * @param \Closure(array<string, mixed>): AgentToolResult $handler
             * @param array<string, mixed> $schema
             */
            public function __construct(
                private readonly \Closure $handler,
                private readonly array $schema,
            ) {}

            public function execute(array $arguments, \Waaseyaa\Access\AuthorizationPrincipalInterface $account): AgentToolResult
            {
                return ($this->handler)($arguments);
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
                return $this->schema;
            }

            public function description(): string
            {
                return 'Correlation integration fixture.';
            }
        };

        return new AgentTool(
            name: 'bimaaji_search_specs',
            capability: 'bimaaji.read',
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: $schema,
            impl: $impl,
            outputSchema: $outputSchema,
        );
    }

    private function registry(AgentTool $tool): ToolRegistryInterface
    {
        return new class ($tool) implements ToolRegistryInterface {
            public function __construct(private readonly AgentTool $tool) {}

            public function register(AgentTool $tool): void
            {
                throw new \LogicException('The fixture registry is immutable.');
            }

            public function get(string $name): AgentTool
            {
                return $name === $this->tool->name
                    ? $this->tool
                    : throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return $name === $this->tool->name;
            }

            public function all(): iterable
            {
                return [$this->tool];
            }
        };
    }
}
