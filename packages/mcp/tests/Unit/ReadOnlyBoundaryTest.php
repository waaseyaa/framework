<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Mcp\Auth\PublicAnonymousAuth;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;
use Waaseyaa\Mcp\ReadOnlyToolRegistry;

/**
 * Security gate for the public read-only MCP boundary. Proves a write /
 * destructive tool is structurally unreachable anonymously at each independent
 * layer: (1) the tool allowlist, (2) the per-tool capability grant.
 */
#[CoversClass(ReadOnlyToolRegistry::class)]
#[CoversClass(PublicAnonymousAuth::class)]
final class ReadOnlyBoundaryTest extends TestCase
{
    private const READ_CAP = 'tool.entity.read';
    private const WRITE_CAP = 'tool.entity.create';

    private function tool(string $name, string $capability, bool $destructive): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return $this->execute($arguments, $account);
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function description(): string
            {
                return 'stub';
            }
        };

        return new AgentTool($name, $capability, $destructive, false, 'test', ['type' => 'object'], $impl);
    }

    private function innerRegistry(): ToolRegistryInterface
    {
        return new class implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            public array $tools = [];

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
                return array_values($this->tools);
            }
        };
    }

    private function populatedReadOnlyRegistry(): ReadOnlyToolRegistry
    {
        $inner = $this->innerRegistry();
        $inner->register($this->tool('entity.read', self::READ_CAP, false));
        $inner->register($this->tool('entity.create', self::WRITE_CAP, true));

        return new ReadOnlyToolRegistry($inner, PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES);
    }

    // ---- Layer 1: tool allowlist (structural) -------------------------------

    #[Test]
    public function write_tool_is_absent_from_the_listing(): void
    {
        $names = [];
        foreach ($this->populatedReadOnlyRegistry()->all() as $tool) {
            $names[] = $tool->name;
        }

        self::assertContains('entity.read', $names);
        self::assertNotContains('entity.create', $names, 'A destructive tool must never appear in tools/list.');
    }

    #[Test]
    public function get_write_tool_throws_as_if_unregistered(): void
    {
        $registry = $this->populatedReadOnlyRegistry();
        self::assertFalse($registry->has('entity.create'));
        $this->expectException(ToolNotFoundException::class);
        $registry->get('entity.create');
    }

    #[Test]
    public function read_tool_remains_reachable(): void
    {
        $registry = $this->populatedReadOnlyRegistry();
        self::assertTrue($registry->has('entity.read'));
        self::assertSame('entity.read', $registry->get('entity.read')->name);
    }

    #[Test]
    public function registering_a_write_tool_is_rejected(): void
    {
        $registry = new ReadOnlyToolRegistry($this->innerRegistry());
        $this->expectException(\LogicException::class);
        $registry->register($this->tool('entity.delete', 'tool.entity.delete', true));
    }

    // ---- Through the MCP bridge (tools/list + tools/call) --------------------

    #[Test]
    public function bridge_listing_excludes_write_tool_and_call_rejects_it(): void
    {
        $bridge = new AgentToolRegistryBridge(
            $this->populatedReadOnlyRegistry(),
            new PublicAnonymousAuth()->authenticate(null),
        );

        $listed = array_map(static fn(AgentTool $t): string => $t->name, $bridge->getTools());
        self::assertSame(['entity.read'], $listed);

        // tools/call on the hidden write tool is rejected (unknown tool).
        self::assertNull($bridge->getTool('entity.create'));
        $result = $bridge->execute('entity.create', []);
        self::assertTrue($result['isError'] ?? false);

        // The read tool works anonymously.
        $ok = $bridge->execute('entity.read', []);
        self::assertArrayNotHasKey('isError', $ok);
    }

    // ---- Layer 2: capability grant ------------------------------------------

    #[Test]
    public function anonymous_account_grants_only_read_capabilities(): void
    {
        $account = new PublicAnonymousAuth()->authenticate(null);

        self::assertFalse($account->isAuthenticated());
        self::assertTrue($account->hasPermission(self::READ_CAP));
        self::assertFalse($account->hasPermission('bimaaji.read'));
        self::assertTrue($account->hasPermission('tool.relationship.traverse'));
        self::assertFalse($account->hasPermission('tool.content.search'));
        // The write capability is NOT granted — a write tool's requireCapability
        // would fail even if it somehow reached execution.
        self::assertFalse($account->hasPermission(self::WRITE_CAP));
        self::assertFalse($account->hasPermission('tool.entity.delete'));
    }

    #[Test]
    public function content_search_can_be_granted_explicitly_without_widening_other_defaults(): void
    {
        $capabilities = [...PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES, 'tool.content.search'];
        $account = new PublicAnonymousAuth($capabilities)->authenticate(null);

        self::assertTrue($account->hasPermission('tool.content.search'));
        self::assertFalse($account->hasPermission(self::WRITE_CAP));
    }

    #[Test]
    public function public_auth_never_returns_null(): void
    {
        $auth = new PublicAnonymousAuth();
        self::assertNotNull($auth->authenticate(null));
        self::assertNotNull($auth->authenticate(''));
        self::assertNotNull($auth->authenticate('Bearer whatever'));
    }

    #[Test]
    public function delegate_authenticated_account_takes_precedence(): void
    {
        $delegateAccount = $this->createMock(\Waaseyaa\Access\AuthorizationPrincipalInterface::class);
        $delegate = new class ($delegateAccount) implements \Waaseyaa\Mcp\Auth\McpAuthInterface {
            public function __construct(private readonly \Waaseyaa\Access\AuthorizationPrincipalInterface $account) {}

            public function authenticate(?string $authorizationHeader): ?\Waaseyaa\Access\AuthorizationPrincipalInterface
            {
                return $authorizationHeader === 'Bearer good' ? $this->account : null;
            }
        };

        $auth = new PublicAnonymousAuth(null, $delegate);

        self::assertSame($delegateAccount, $auth->authenticate('Bearer good'));
        // Falls back to anonymous read when the delegate declines.
        self::assertFalse($auth->authenticate('Bearer bad')->isAuthenticated());
    }
}
