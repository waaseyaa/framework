<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;
use Waaseyaa\Mcp\Bridge\ToolExecutionOutcome;

/**
 * #2657: `AgentToolRegistryBridge` became a façade over the transport-neutral
 * `Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher` (ADR-022 D-9.3). This is the
 * regression that says the change was a relocation and not a rewrite.
 *
 * Every assertion here is a *parity* assertion: the façade and the dispatcher
 * are driven over the same registry and principal, and their envelopes and
 * stages must match exactly. A façade that quietly reshapes an envelope would
 * be a wire change disguised as a refactor, and the two MCP tiers' contracts —
 * the `TOOL_NOT_FOUND` / `VALIDATION_FAILED` / `INTERNAL_ERROR` vocabulary, the
 * name-ordered `tools/list`, the `mcp.tool_execution_failed` log key an
 * operator greps for — are exactly the things nobody would notice drifting.
 */
#[CoversClass(AgentToolRegistryBridge::class)]
#[CoversClass(ToolExecutionOutcome::class)]
final class AgentToolRegistryBridgeFacadeParityTest extends TestCase
{
    #[Test]
    public function listing_matches_the_underlying_dispatcher_and_stays_name_ordered(): void
    {
        $registry = $this->registry([$this->tool('zulu'), $this->tool('alpha')]);

        self::assertSame(
            ['alpha', 'zulu'],
            array_map(static fn(AgentTool $t): string => $t->name, $this->bridge($registry)->getTools()),
        );
        self::assertSame(
            array_map(static fn(AgentTool $t): string => $t->name, $this->dispatcher($registry)->tools()),
            array_map(static fn(AgentTool $t): string => $t->name, $this->bridge($registry)->getTools()),
        );
    }

    #[Test]
    public function get_tool_returns_null_for_an_unknown_name_rather_than_throwing(): void
    {
        $registry = $this->registry([$this->tool('probe')]);

        self::assertNull($this->bridge($registry)->getTool('absent'));
        self::assertNotNull($this->bridge($registry)->getTool('probe'));
    }

    #[Test]
    public function the_unknown_tool_envelope_is_identical_to_the_dispatchers(): void
    {
        $registry = $this->registry([]);

        $outcome = $this->bridge($registry)->executeClassified('missing', ['a' => 1]);

        self::assertSame(AuditStage::ToolLookupRefused, $outcome->stage);
        self::assertTrue($outcome->isError());
        self::assertSame(
            $this->dispatcher($registry)->dispatch('missing', ['a' => 1])->envelope,
            $outcome->envelope,
        );
    }

    #[Test]
    public function the_schema_violation_envelope_is_identical_to_the_dispatchers(): void
    {
        $registry = $this->registry([
            $this->tool('probe', schema: ['type' => 'object', 'required' => ['q'], 'properties' => ['q' => ['type' => 'string']]]),
        ]);

        $outcome = $this->bridge($registry)->executeClassified('probe', []);

        self::assertSame(AuditStage::InputValidationRefused, $outcome->stage);
        self::assertSame(
            $this->dispatcher($registry)->dispatch('probe', [])->envelope,
            $outcome->envelope,
        );
    }

    #[Test]
    public function execute_returns_the_bare_envelope_that_execute_classified_carries(): void
    {
        $registry = $this->registry([$this->tool('probe')]);
        $bridge = $this->bridge($registry);

        self::assertSame(
            $bridge->executeClassified('probe', [])->envelope,
            $bridge->execute('probe', []),
        );
    }

    #[Test]
    public function the_mcp_log_key_survives_the_extraction(): void
    {
        // The dispatcher's default prefix is `tool_dispatch`; the façade must
        // pass `mcp` so an operator's existing search for
        // `mcp.tool_execution_failed` keeps finding this.
        $logger = new class implements LoggerInterface {
            /** @var list<string> */
            public array $messages = [];

            public function emergency(string|\Stringable $m, array $c = []): void {}

            public function alert(string|\Stringable $m, array $c = []): void {}

            public function critical(string|\Stringable $m, array $c = []): void {}

            public function error(string|\Stringable $m, array $c = []): void
            {
                $this->messages[] = (string) $m;
            }

            public function warning(string|\Stringable $m, array $c = []): void {}

            public function notice(string|\Stringable $m, array $c = []): void {}

            public function info(string|\Stringable $m, array $c = []): void {}

            public function debug(string|\Stringable $m, array $c = []): void {}

            public function log(LogLevel $level, string|\Stringable $m, array $c = []): void {}
        };

        $registry = $this->registry([
            $this->tool('probe', handler: static fn(): AgentToolResult => throw new \RuntimeException('boom')),
        ]);

        new AgentToolRegistryBridge($registry, $this->principal(), $logger)->execute('probe', []);

        self::assertSame(['mcp.tool_execution_failed'], $logger->messages);
    }

    // ------------------------------------------------------------- fixtures

    private function bridge(ToolRegistryInterface $registry): AgentToolRegistryBridge
    {
        return new AgentToolRegistryBridge($registry, $this->principal());
    }

    private function dispatcher(ToolRegistryInterface $registry): AgentToolDispatcher
    {
        return new AgentToolDispatcher($registry, $this->principal());
    }

    private function principal(): AuthorizationPrincipalInterface
    {
        return new class implements AuthorizationPrincipalInterface {
            public function id(): int|string
            {
                return 'parity:principal';
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }

            public function claimsGeneration(): string
            {
                return 'parity';
            }

            public function tenantId(): ?string
            {
                return null;
            }

            public function communityId(): ?string
            {
                return null;
            }
        };
    }

    /** @param list<AgentTool> $tools */
    private function registry(array $tools): ToolRegistryInterface
    {
        return new class ($tools) implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $tools = [];

            /** @param list<AgentTool> $tools */
            public function __construct(array $tools)
            {
                foreach ($tools as $tool) {
                    $this->tools[$tool->name] = $tool;
                }
            }

            public function register(AgentTool $tool): void
            {
                $this->tools[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                return $this->tools[$name] ?? throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return isset($this->tools[$name]);
            }

            public function all(): iterable
            {
                yield from array_values($this->tools);
            }
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @param ?\Closure(): AgentToolResult $handler
     */
    private function tool(string $name, array $schema = ['type' => 'object'], ?\Closure $handler = null): AgentTool
    {
        $impl = new class ($schema, $handler) implements AgentToolInterface {
            /** @param array<string, mixed> $schema */
            public function __construct(
                private readonly array $schema,
                private readonly ?\Closure $handler,
            ) {}

            public function execute(array $arguments, AuthorizationPrincipalInterface $account): AgentToolResult
            {
                if ($this->handler !== null) {
                    return ($this->handler)();
                }

                return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
            }

            public function dryRun(array $arguments, AuthorizationPrincipalInterface $account): AgentToolResult
            {
                return $this->execute($arguments, $account);
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
                return 'Parity probe.';
            }
        };

        return new AgentTool(
            name: $name,
            capability: 'probe.read',
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: $schema,
            impl: $impl,
        );
    }
}
