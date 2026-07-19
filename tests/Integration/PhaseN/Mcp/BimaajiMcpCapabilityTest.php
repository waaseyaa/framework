<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpEndpoint;

/**
 * WP03 capability-gating coverage for the bimaaji-mcp bridge.
 *
 * Drives `McpEndpoint::handle()` end-to-end with two account fixtures:
 *
 *  1. Read-only account (`bimaaji.read`). `tools/call` on a
 *     `bimaaji.read` tool succeeds; `tools/call` on a `bimaaji.mutate`
 *     tool returns the structured forbidden envelope.
 *  2. Read+mutate account. Both calls succeed.
 *
 * Also pins NFR-003: forbidden-path response time is under 50 ms.
 * The capability check itself is a single `array_search`-equivalent
 * inside `AbstractAgentTool::requireCapability` — fast enough to satisfy
 * the spec's 5 ms microbenchmark target; the 50 ms ceiling here
 * accounts for PHPUnit + symfony Request creation overhead.
 *
 * @api
 */
#[CoversNothing]
final class BimaajiMcpCapabilityTest extends TestCase
{
    #[Test]
    public function readOnlyAccountSucceedsOnReadToolButIsForbiddenOnMutateTool(): void
    {
        $endpoint = $this->endpointFor($this->accountWith(['bimaaji.read']));

        $readBody = $this->dispatch($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'bimaaji_introspect_graph', 'arguments' => []],
        ]);

        self::assertArrayHasKey('result', $readBody);
        self::assertArrayNotHasKey('isError', $readBody['result']);
        self::assertSame('json', $readBody['result']['content'][0]['type']);

        $mutateBody = $this->dispatch($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'bimaaji_propose_mutation', 'arguments' => []],
        ]);

        self::assertArrayHasKey('result', $mutateBody);
        self::assertTrue(
            $mutateBody['result']['isError'] ?? false,
            'mutation tool must surface isError=true when account lacks bimaaji.mutate.',
        );
        $text = $mutateBody['result']['content'][0]['text'] ?? '';
        self::assertStringContainsString('not permitted', $text);
        self::assertStringContainsString('bimaaji.mutate', $text);
    }

    #[Test]
    public function readAndMutateAccountSucceedsOnBothToolKinds(): void
    {
        $endpoint = $this->endpointFor($this->accountWith(['bimaaji.read', 'bimaaji.mutate']));

        foreach (['bimaaji_introspect_graph', 'bimaaji_propose_mutation'] as $toolName) {
            $body = $this->dispatch($endpoint, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $toolName, 'arguments' => []],
            ]);

            self::assertArrayHasKey('result', $body, "Expected success for {$toolName}");
            self::assertArrayNotHasKey('isError', $body['result'], "Expected non-error for {$toolName}");
        }
    }

    #[Test]
    public function forbiddenPathResponseTimeIsUnder50ms(): void // NFR-003 with integration slack
    {
        $endpoint = $this->endpointFor($this->accountWith(['bimaaji.read']));

        $start = hrtime(true);
        $body = $this->dispatch($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'bimaaji_propose_mutation', 'arguments' => []],
        ]);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertTrue($body['result']['isError'] ?? false);
        self::assertLessThan(50.0, $elapsedMs, sprintf(
            'Forbidden-path dispatch took %.2f ms (NFR-003 budget: 50 ms with integration slack).',
            $elapsedMs,
        ));
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

        return new McpEndpoint(auth: $auth, agentRegistry: $this->stubRegistry());
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
        return new AuthorizationPrincipal(7, true, [], $permissions, 'test');
    }

    private function stubRegistry(): AgentToolRegistryInterface
    {
        $tools = [
            $this->descriptorFor('bimaaji_introspect_graph', 'bimaaji.read'),
            $this->descriptorFor('bimaaji_propose_mutation', 'bimaaji.mutate'),
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
