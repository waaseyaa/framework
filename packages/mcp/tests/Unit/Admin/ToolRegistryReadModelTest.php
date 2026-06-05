<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Api\McpAdmin\RecentInvocation;
use Waaseyaa\Mcp\Admin\RecentInvocationsQueryInterface;
use Waaseyaa\Mcp\Admin\ToolRegistryReadModel;

#[CoversClass(ToolRegistryReadModel::class)]
final class ToolRegistryReadModelTest extends TestCase
{
    // ── listTools() ──────────────────────────────────────────────────────────

    #[Test]
    public function listToolsReturnsMappedRows(): void
    {
        $registry = $this->createFakeAgentRegistry();
        $model = new ToolRegistryReadModel(agentRegistry: $registry);

        $rows = $model->listTools();

        $this->assertCount(2, $rows);
        $first = $rows[0];
        $this->assertSame('bimaaji_search', $first->name);
        $this->assertSame('bimaaji', $first->category);
        $this->assertSame(['bimaaji.read'], $first->requiredCapabilities);
        $this->assertSame('Search the specs directory.', $first->summary);
    }

    #[Test]
    public function listToolsWithEmptyRegistryReturnsEmptyArray(): void
    {
        $registry = new class implements AgentToolRegistryInterface {
            public function register(AgentTool $tool): void {}
            public function get(string $name): AgentTool { throw new \RuntimeException('not found'); }
            public function has(string $name): bool { return false; }
            public function all(): iterable { return []; }
        };

        $model = new ToolRegistryReadModel(agentRegistry: $registry);

        $this->assertSame([], $model->listTools());
    }

    #[Test]
    public function listToolsSummaryIsNullWhenDescriptionEmpty(): void
    {
        $registry = new class implements AgentToolRegistryInterface {
            public function register(AgentTool $tool): void {}
            public function get(string $name): AgentTool { throw new \RuntimeException('not found'); }
            public function has(string $name): bool { return false; }
            public function all(): iterable
            {
                $impl = new class implements AgentToolInterface {
                    public function description(): string { return ''; }
                    public function inputSchema(): array { return []; }
                    public function argumentsForAudit(array $arguments): array { return []; }
                    public function execute(array $arguments, AccountInterface $account): AgentToolResult
                    {
                        return AgentToolResult::success([]);
                    }
                    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
                    {
                        return AgentToolResult::success([]);
                    }
                };
                yield new AgentTool(
                    name: 'empty_desc_tool',
                    capability: 'entity.view',
                    destructive: false,
                    dryRunSupported: false,
                    category: 'entity',
                    inputSchema: [],
                    impl: $impl,
                );
            }
        };

        $model = new ToolRegistryReadModel(agentRegistry: $registry);
        $rows = $model->listTools();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->summary);
    }

    // ── findTool() ───────────────────────────────────────────────────────────

    #[Test]
    public function findToolReturnsNullWhenNotFound(): void
    {
        $registry = $this->createFakeAgentRegistry();
        $model = new ToolRegistryReadModel(agentRegistry: $registry);

        $this->assertNull($model->findTool('nonexistent'));
    }

    #[Test]
    public function findToolReturnsDetailWithInputSchemaAndEmptyInvocations(): void
    {
        $registry = $this->createFakeAgentRegistry();
        $model = new ToolRegistryReadModel(agentRegistry: $registry);

        $detail = $model->findTool('bimaaji_search');

        $this->assertNotNull($detail);
        $this->assertSame('bimaaji_search', $detail->name);
        $this->assertSame('bimaaji', $detail->category);
        $this->assertSame(['bimaaji.read'], $detail->requiredCapabilities);
        $this->assertArrayHasKey('type', $detail->inputSchema);
        $this->assertSame([], $detail->recentInvocations);
    }

    #[Test]
    public function findToolPopulatesRecentInvocationsWhenQueryPresent(): void
    {
        $registry = $this->createFakeAgentRegistry();

        $invocationQuery = new class implements RecentInvocationsQueryInterface {
            public function recentForTool(string $toolName, int $limit): array
            {
                if ($toolName === 'bimaaji_search') {
                    return [
                        new RecentInvocation(
                            traceUuid: '550e8400-e29b-41d4-a716-446655440000',
                            invokedAt: '2026-05-25T05:00:00Z',
                            account: 'admin@example.com',
                            outcome: 'ok',
                            errorMessage: null,
                            latencyMs: 42,
                        ),
                    ];
                }
                return [];
            }
        };

        $model = new ToolRegistryReadModel(
            agentRegistry: $registry,
            invocationQuery: $invocationQuery,
        );

        $detail = $model->findTool('bimaaji_search');

        $this->assertNotNull($detail);
        $this->assertCount(1, $detail->recentInvocations);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $detail->recentInvocations[0]->traceUuid);
        $this->assertSame('ok', $detail->recentInvocations[0]->outcome);
    }

    #[Test]
    public function findToolReturnsEmptyInvocationsWhenQueryAbsent(): void
    {
        $registry = $this->createFakeAgentRegistry();
        $model = new ToolRegistryReadModel(agentRegistry: $registry); // no invocationQuery

        $detail = $model->findTool('bimaaji_search');

        $this->assertNotNull($detail);
        $this->assertSame([], $detail->recentInvocations);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createFakeAgentRegistry(): AgentToolRegistryInterface
    {
        return new class implements AgentToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $tools;

            public function __construct()
            {
                $bimaaji = new class implements AgentToolInterface {
                    public function description(): string { return 'Search the specs directory.'; }
                    public function inputSchema(): array
                    {
                        return ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]];
                    }
                    public function argumentsForAudit(array $arguments): array { return $arguments; }
                    public function execute(array $arguments, AccountInterface $account): AgentToolResult
                    {
                        return AgentToolResult::success([]);
                    }
                    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
                    {
                        return AgentToolResult::success([]);
                    }
                };

                $entity = new class implements AgentToolInterface {
                    public function description(): string { return 'Read entity data.'; }
                    public function inputSchema(): array
                    {
                        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
                    }
                    public function argumentsForAudit(array $arguments): array { return $arguments; }
                    public function execute(array $arguments, AccountInterface $account): AgentToolResult
                    {
                        return AgentToolResult::success([]);
                    }
                    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
                    {
                        return AgentToolResult::success([]);
                    }
                };

                $this->tools = [
                    'bimaaji_search' => new AgentTool(
                        name: 'bimaaji_search',
                        capability: 'bimaaji.read',
                        destructive: false,
                        dryRunSupported: true,
                        category: 'bimaaji',
                        inputSchema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
                        impl: $bimaaji,
                    ),
                    'entity_read' => new AgentTool(
                        name: 'entity_read',
                        capability: 'entity.view',
                        destructive: false,
                        dryRunSupported: false,
                        category: 'entity',
                        inputSchema: ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                        impl: $entity,
                    ),
                ];
            }

            public function register(AgentTool $tool): void
            {
                $this->tools[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                return $this->tools[$name] ?? throw new \RuntimeException("Tool not found: $name");
            }

            public function has(string $name): bool
            {
                return isset($this->tools[$name]);
            }

            public function all(): iterable
            {
                return array_values($this->tools);
            }
        };
    }
}
