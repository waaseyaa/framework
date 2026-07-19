<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\AI\Agent\Tool\Bimaaji\GeneratePatchTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectGraphTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\ProposeMutationTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\SearchSpecsTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpEndpoint;

/**
 * WP02+WP03 end-to-end test for the bimaaji-mcp bridge wiring.
 *
 * Drives `McpEndpoint::handle()` against a stub agent registry hydrated
 * with the five canonical bimaaji read-tool descriptors. The endpoint
 * constructs the per-request `AgentToolRegistryBridge` internally with
 * the auth-resolved account (WP03 closing fix for the WP02 caveat).
 *
 * Asserts:
 *  - The full `tools/list` JSON-RPC payload contains all five bimaaji
 *    read-tool names.
 *  - `tools/call(bimaaji_search_specs)` returns a `success` envelope
 *    when the auth account has `bimaaji.read`.
 *
 * Capability negative-path coverage (forbidden envelope when capability
 * missing) lives in {@see BimaajiMcpCapabilityTest}.
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
    public function toolsListSurfacesAllFiveCanonicalBimaajiReadTools(): void
    {
        $endpoint = $this->endpointFor($this->accountWith(['bimaaji.read']));

        $body = $this->dispatch($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        self::assertArrayHasKey('result', $body);
        $names = array_map(static fn(array $tool): string => $tool['name'], $body['result']['tools']);
        sort($names);

        $expected = self::CANONICAL_BIMAAJI_READ_TOOLS;
        sort($expected);

        self::assertSame($expected, $names);
    }

    #[Test]
    public function toolsCallSucceedsForBimaajiReadAccount(): void // closes WP02 caveat
    {
        $endpoint = $this->endpointFor($this->accountWith(['bimaaji.read']));

        $body = $this->dispatch($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'bimaaji_search_specs',
                'arguments' => ['query' => 'anything'],
            ],
        ]);

        self::assertArrayHasKey('result', $body, sprintf(
            'WP03 should return a success envelope; got: %s',
            \json_encode($body, \JSON_THROW_ON_ERROR),
        ));
        self::assertArrayNotHasKey('isError', $body['result']);
        self::assertSame('json', $body['result']['content'][0]['type']);
        self::assertSame(['ok' => true], $body['result']['content'][0]['data']);
    }

    #[Test]
    public function classExistsAssertionsPinTheSc004Surface(): void
    {
        foreach ([
            IntrospectGraphTool::class,
            IntrospectSectionTool::class,
            ProposeMutationTool::class,
            GeneratePatchTool::class,
            SearchSpecsTool::class,
        ] as $class) {
            self::assertTrue(class_exists($class), "Bimaaji read tool class missing: {$class}");
        }
    }

    private function endpointFor(AccountInterface $account): McpEndpoint
    {
        $auth = new class ($account) implements McpAuthInterface {
            public function __construct(private readonly AccountInterface $account) {}

            public function authenticate(?string $authorizationHeader): ?AccountInterface
            {
                return $authorizationHeader !== null ? $this->account : null;
            }
        };

        return new McpEndpoint(auth: $auth, agentRegistry: $this->stubRegistryWithAllFiveTools());
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatch(McpEndpoint $endpoint, array $payload): array
    {
        $request = HttpRequest::create(
            uri: '/mcp',
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['HTTP_AUTHORIZATION' => 'Bearer x'],
            content: \json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        $response = $endpoint->handle($this->accountWith([]), $request);
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        \assert(is_array($decoded));

        return $decoded;
    }

    /**
     * @param list<string> $permissions
     */
    private function accountWith(array $permissions): AccountInterface
    {
        return new AuthorizationPrincipal(42, true, [], $permissions, 'test');
    }

    private function stubRegistryWithAllFiveTools(): AgentToolRegistryInterface
    {
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
        $impl = new class ($capability) implements AgentToolInterface {
            public function __construct(private readonly string $cap) {}

            public function description(): string
            {
                return sprintf('Stub bimaaji tool (capability=%s).', $this->cap);
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'additionalProperties' => true];
            }

            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                if (!$account->hasPermission($this->cap)) {
                    return AgentToolResult::error(
                        message: sprintf('Account %s is not permitted to call %s', $account->id(), $this->cap),
                        summary: 'forbidden',
                    );
                }

                return AgentToolResult::success(
                    content: [['type' => 'json', 'data' => ['ok' => true]]],
                    summary: 'ok',
                );
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
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
