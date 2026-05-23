<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Tool\Bimaaji\GeneratePatchTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectGraphTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\ProposeMutationTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\SearchSpecsTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;
use Waaseyaa\Mcp\Bridge\ToolExecutorInterface;
use Waaseyaa\Mcp\Bridge\ToolRegistryInterface;
use Waaseyaa\Mcp\McpServiceProvider;

/**
 * WP02 end-to-end test for the bimaaji-mcp bridge wiring.
 *
 * Stands up `McpServiceProvider` against a stub `AgentToolRegistryInterface`
 * hydrated with the five canonical bimaaji read-tool descriptors and asserts
 * the bridge surfaces them as MCP `getTools()` + that `tools/call` correctly
 * returns the documented `forbidden` envelope for the placeholder
 * no-permission account.
 *
 * **WP02 limitation pinned by this test:** the bridge takes
 * `AccountInterface` at construction (per-request account passthrough is a
 * WP03 concern). The placeholder account has zero permissions, so every
 * capability-gated tool returns `forbidden` via the
 * `AgentToolRegistryBridge::execute` → `requireCapability` chain. The
 * `forbidden` envelope is the contract; a `success` envelope here would
 * mean WP03 silently shipped early without docs/spec updates.
 *
 * @api
 */
#[CoversNothing]
final class BimaajiMcpReadTest extends TestCase
{
    private const array CANONICAL_BIMAAJI_READ_TOOLS = [
        'bimaaji_introspect_graph',
        'bimaaji_introspect_section',
        'bimaaji_propose_mutation',
        'bimaaji_generate_patch',
        'bimaaji_search_specs',
    ];

    #[Test]
    public function bridgeResolvesAsBothMcpInterfacesViaSingleInstance(): void
    {
        $provider = $this->bootedProvider($this->stubRegistryWithAllFiveTools());

        $registry = $provider->resolve(ToolRegistryInterface::class);
        $executor = $provider->resolve(ToolExecutorInterface::class);

        self::assertInstanceOf(AgentToolRegistryBridge::class, $registry);
        self::assertSame($registry, $executor);
    }

    #[Test]
    public function bridgeListsAllFiveCanonicalBimaajiReadTools(): void
    {
        $provider = $this->bootedProvider($this->stubRegistryWithAllFiveTools());
        /** @var ToolRegistryInterface $registry */
        $registry = $provider->resolve(ToolRegistryInterface::class);

        $names = array_map(static fn(AgentTool $tool): string => $tool->name, $registry->getTools());
        sort($names);

        $expected = self::CANONICAL_BIMAAJI_READ_TOOLS;
        sort($expected);

        self::assertSame($expected, $names);
    }

    #[Test]
    public function toolCallReturnsForbiddenEnvelopeForPlaceholderAccount(): void
    {
        $provider = $this->bootedProvider($this->stubRegistryWithAllFiveTools());
        /** @var ToolExecutorInterface $executor */
        $executor = $provider->resolve(ToolExecutorInterface::class);

        $result = $executor->execute('bimaaji_search_specs', ['query' => 'anything']);

        self::assertTrue($result['isError'] ?? false, 'WP02 placeholder account must yield isError=true.');
        $text = $result['content'][0]['text'] ?? '';
        self::assertStringContainsString('not permitted', $text, 'forbidden envelope must surface the no-permission text.');
    }

    private function bootedProvider(AgentToolRegistryInterface $registry): McpServiceProvider
    {
        $provider = new McpServiceProvider();
        $provider->setKernelContext(projectRoot: '', config: [], manifestFormatters: []);
        $provider->setKernelServices(new class ($registry) implements KernelServicesInterface {
            public function __construct(private readonly AgentToolRegistryInterface $registry) {}

            public function get(string $abstract): ?object
            {
                if ($abstract === AgentToolRegistryInterface::class) {
                    return $this->registry;
                }

                return null;
            }
        });
        $provider->register();

        return $provider;
    }

    private function stubRegistryWithAllFiveTools(): AgentToolRegistryInterface
    {
        // The class-string column documents which production tool each
        // descriptor mirrors (assertion below pins each FQCN exists);
        // descriptorFor() itself only needs the name+capability tuple.
        foreach ([IntrospectGraphTool::class, IntrospectSectionTool::class, ProposeMutationTool::class, GeneratePatchTool::class, SearchSpecsTool::class] as $class) {
            self::assertTrue(class_exists($class), "Bimaaji read tool class missing: {$class}");
        }

        $tools = [
            $this->descriptorFor('bimaaji_introspect_graph', 'bimaaji.read'),
            $this->descriptorFor('bimaaji_introspect_section', 'bimaaji.read'),
            $this->descriptorFor('bimaaji_propose_mutation', 'bimaaji.mutate'),
            $this->descriptorFor('bimaaji_generate_patch', 'bimaaji.mutate'),
            $this->descriptorFor('bimaaji_search_specs', 'bimaaji.read'),
        ];

        return new class ($tools) implements AgentToolRegistryInterface {
            /** @param list<AgentTool> $tools */
            public function __construct(private readonly array $tools) {}

            public function register(AgentTool $tool): void {}

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

    private function descriptorFor(string $name, string $capability): AgentTool
    {
        $impl = new class ($name, $capability) implements AgentToolInterface {
            public function __construct(
                private readonly string $toolName,
                private readonly string $cap,
            ) {}

            public function description(): string
            {
                return sprintf('Stub %s (capability=%s).', $this->toolName, $this->cap);
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'additionalProperties' => true];
            }

            public function execute(array $arguments, AccountInterface $account): \Waaseyaa\AI\Tools\AgentToolResult
            {
                if (!$account->hasPermission($this->cap)) {
                    return \Waaseyaa\AI\Tools\AgentToolResult::error(
                        message: sprintf('Account %s is not permitted to call %s', $account->id(), $this->cap),
                        summary: 'forbidden',
                    );
                }

                return \Waaseyaa\AI\Tools\AgentToolResult::success(
                    content: [['type' => 'json', 'data' => ['ok' => true]]],
                    summary: 'ok',
                );
            }

            public function dryRun(array $arguments, AccountInterface $account): \Waaseyaa\AI\Tools\AgentToolResult
            {
                return $this->execute($arguments, $account);
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }
        };

        return new AgentTool(
            name: $name,
            capability: $capability,
            destructive: false,
            dryRunSupported: true,
            category: 'bimaaji',
            inputSchema: $impl->inputSchema(),
            impl: $impl,
        );
    }
}
