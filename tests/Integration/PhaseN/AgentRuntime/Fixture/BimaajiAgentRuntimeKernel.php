<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Agent\Tool\Bimaaji\GeneratePatchTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\ProposeMutationTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;
use Waaseyaa\Bimaaji\Mutation\MutationValidator;
use Waaseyaa\Bimaaji\Patch\PatchGenerator;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Shared boot scaffolding for the M2 WP04 bimaaji end-to-end tests.
 *
 * Wires an in-memory SQLite with the AgentRun + AgentAuditLog schema, a
 * `ToolRegistryInterface` carrying the three bimaaji tools shipped in WP02
 * and WP03 (introspect_section, propose_mutation, generate_patch), and a
 * `ProviderInterface` builder that emits a deterministic three-step
 * tool-use sequence so the executor's audit log is reproducible.
 *
 * Two tests share this scaffolding:
 *
 *  - `BimaajiAgentRunTest`           (positive path, FR-011 / SC-001 / SC-002)
 *  - `BimaajiAgentRunCapabilityTest` (negative path, FR-010 / SC-003)
 *
 * The bimaaji graph is built inline with a single `fixture_demo` entity
 * type so the demo agent's mutation targets resolve without booting the
 * full kernel's `EntityIntrospectionProvider`.
 *
 * @api
 */
final class BimaajiAgentRuntimeKernel
{
    public readonly DBALDatabase $database;
    public readonly AgentRunRepository $runRepository;
    public readonly AgentAuditLogRepository $auditRepository;
    public readonly MutationValidator $validator;
    public readonly PatchGenerator $patchGenerator;
    public readonly ApplicationGraphGenerator $graphGenerator;

    public function __construct()
    {
        $this->database = DBALDatabase::createSqlite();
        $this->migrateSchema();

        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver);

        $runType = new EntityType(
            id: 'agent_run',
            label: 'Agent run',
            class: AgentRun::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
        );
        $logType = new EntityType(
            id: 'agent_audit_log',
            label: 'Agent audit log',
            class: AgentAuditLog::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'event_type'],
        );

        $runEntityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create($runType, $driver, $dispatcher);
        $logEntityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create($logType, $driver, $dispatcher);

        $this->runRepository = new AgentRunRepository($runEntityRepo, $this->database);
        $this->auditRepository = new AgentAuditLogRepository($logEntityRepo, $this->database);

        $this->graphGenerator = new ApplicationGraphGenerator(providers: [
            $this->makeEntitiesProvider(['fixture_demo' => ['label' => 'Fixture Demo', 'class' => 'Test\\Fixture\\Demo']]),
            $this->makeStubProvider('admin', ['entity_types' => ['fixture_demo']]),
            $this->makeStubProvider('jsonapi', ['routes' => []]),
            $this->makeStubProvider('public_surface', ['routes' => []]),
            $this->makeStubProvider('routing', ['routes' => []]),
            $this->makeStubProvider('sovereignty', ['profile' => 'self_hosted']),
        ]);

        $graph = $this->graphGenerator->generate();
        $this->validator = new MutationValidator($graph);
        $this->patchGenerator = new PatchGenerator();
    }

    public function executor(ToolRegistryInterface $registry): AgentExecutor
    {
        return new AgentExecutor(
            toolRegistry: $registry,
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            transcriptMaxBytes: 65536,
            hitlPollIntervalMs: 1,
            hitlTimeoutSeconds: 1,
            sleepMs: static fn(int $ms): null => null,
        );
    }

    /**
     * Build a tool registry holding the three bimaaji tools WP02 + WP03
     * shipped — wrapped in {@see AgentTool} envelopes matching their
     * `#[AsAgentTool]` attribute metadata.
     */
    public function bimaajiToolRegistry(): ToolRegistryInterface
    {
        $tools = [
            new AgentTool(
                name: 'bimaaji_introspect_section',
                capability: 'bimaaji.read',
                destructive: false,
                dryRunSupported: true,
                category: 'bimaaji',
                inputSchema: ['type' => 'object', 'properties' => []],
                impl: new IntrospectSectionTool($this->graphGenerator),
            ),
            new AgentTool(
                name: 'bimaaji_propose_mutation',
                capability: 'bimaaji.mutate',
                destructive: false,
                dryRunSupported: true,
                category: 'bimaaji',
                inputSchema: ['type' => 'object', 'properties' => []],
                impl: new ProposeMutationTool($this->validator),
            ),
            new AgentTool(
                name: 'bimaaji_generate_patch',
                capability: 'bimaaji.mutate',
                destructive: false,
                dryRunSupported: true,
                category: 'bimaaji',
                inputSchema: ['type' => 'object', 'properties' => []],
                impl: new GeneratePatchTool($this->patchGenerator, $this->validator),
            ),
        ];

        return new class ($tools) implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            /** @param list<AgentTool> $tools */
            public function __construct(array $tools)
            {
                foreach ($tools as $tool) {
                    $this->map[$tool->name] = $tool;
                }
            }

            public function register(AgentTool $tool): void
            {
                $this->map[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                if (!isset($this->map[$name])) {
                    throw ToolNotFoundException::forName($name);
                }

                return $this->map[$name];
            }

            public function has(string $name): bool
            {
                return isset($this->map[$name]);
            }

            public function all(): iterable
            {
                return array_values($this->map);
            }
        };
    }

    public function seedRun(int $accountId = 99): AgentRun
    {
        $run = new AgentRun([
            'id' => '01J' . str_pad((string) random_int(1000, 9999), 23, '0'),
            'account_id' => $accountId,
            'agent_definition_id' => 'bimaaji_demo',
            'bundle_json' => '{}',
            'status' => RunStatus::Running->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => null,
            'prompt' => 'demo',
            'response' => null,
            'transcript_json' => '',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.uP'),
            'started_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.uP'),
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);
        $this->runRepository->save($run);

        return $run;
    }

    /**
     * Account that grants exactly the listed permissions and nothing else.
     *
     * @param list<string> $permissions
     */
    public function accountWith(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            /** @param list<string> $permissions */
            public function __construct(
                private readonly int $accountId,
                private readonly array $permissions,
            ) {}

            public function id(): int
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    /**
     * Deterministic provider that emits a three-step tool sequence:
     *
     *  1. `bimaaji_introspect_section` with {"section": "entities"}
     *  2. `bimaaji_propose_mutation` with the supplied request args
     *  3. `bimaaji_generate_patch` with the supplied request args
     *  4. `end_turn` text "demo complete"
     *
     * @param array<string, mixed> $mutationArgs
     */
    public function bimaajiDemoProvider(array $mutationArgs): ProviderInterface
    {
        return new class ($mutationArgs) implements ProviderInterface {
            private int $turn = 0;

            /** @param array<string, mixed> $mutationArgs */
            public function __construct(
                private readonly array $mutationArgs,
            ) {}

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->turn++;

                return match ($this->turn) {
                    1 => new MessageResponse(
                        content: [[
                            'type' => 'tool_use',
                            'id' => 'tu_introspect',
                            'name' => 'bimaaji_introspect_section',
                            'input' => ['section' => 'entities'],
                        ]],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 5, 'output_tokens' => 2],
                    ),
                    2 => new MessageResponse(
                        content: [[
                            'type' => 'tool_use',
                            'id' => 'tu_propose',
                            'name' => 'bimaaji_propose_mutation',
                            'input' => $this->mutationArgs,
                        ]],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 5, 'output_tokens' => 2],
                    ),
                    3 => new MessageResponse(
                        content: [[
                            'type' => 'tool_use',
                            'id' => 'tu_generate',
                            'name' => 'bimaaji_generate_patch',
                            'input' => $this->mutationArgs,
                        ]],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 5, 'output_tokens' => 2],
                    ),
                    default => new MessageResponse(
                        content: [['type' => 'text', 'text' => 'demo complete']],
                        stopReason: 'end_turn',
                        usage: ['input_tokens' => 5, 'output_tokens' => 2],
                    ),
                };
            }
        };
    }

    /**
     * Return all audit-log rows for the run, in chronological order.
     *
     * @return list<array<string, mixed>>
     */
    public function auditEventsForRun(string $runId): array
    {
        $iter = $this->database->select('agent_audit_log')
            ->fields('agent_audit_log', ['event_type', 'success', 'tool_name', 'tool_result_summary'])
            ->condition('run_id', $runId)
            ->orderBy('occurred_at', 'ASC')
            ->execute();

        return array_values(iterator_to_array($iter, preserve_keys: false));
    }

    private function migrateSchema(): void
    {
        $migrationFile = \dirname(__DIR__, 5)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);

        $schema = new SchemaBuilder($this->database->getConnection());
        $migration->up($schema);
    }

    /**
     * Build a `GraphSectionProviderInterface` for the `entities` section that
     * returns the supplied map. The shape mirrors what
     * `EntityIntrospectionProvider` would yield in a booted kernel.
     *
     * @param array<string, array<string, mixed>> $entities
     */
    private function makeEntitiesProvider(array $entities): GraphSectionProviderInterface
    {
        return new class ($entities) implements GraphSectionProviderInterface {
            /** @param array<string, array<string, mixed>> $entities */
            public function __construct(private readonly array $entities) {}

            public function getKey(): string
            {
                return 'entities';
            }

            public function provide(): GraphSection
            {
                return new GraphSection(key: 'entities', version: '1.0', data: $this->entities);
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function makeStubProvider(string $key, array $data): GraphSectionProviderInterface
    {
        return new class ($key, $data) implements GraphSectionProviderInterface {
            /** @param array<string, mixed> $data */
            public function __construct(
                private readonly string $key,
                private readonly array $data,
            ) {}

            public function getKey(): string
            {
                return $this->key;
            }

            public function provide(): GraphSection
            {
                return new GraphSection(key: $this->key, version: '1.0', data: $this->data);
            }
        };
    }
}
