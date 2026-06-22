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
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\ReadOnlyToolRegistry;

/**
 * The authenticated write tier's structural layer ({@see CapabilityScopedToolRegistry}):
 * it exposes exactly the allowlisted capabilities — destructive tools included —
 * and nothing else, the dual of the public read-only boundary. The cross-check
 * proves C-001: the very tool the write tier exposes stays hidden on the public
 * {@see ReadOnlyToolRegistry} surface.
 */
#[CoversClass(CapabilityScopedToolRegistry::class)]
final class CapabilityScopedToolRegistryTest extends TestCase
{
    private const string WRITE_CAP = 'present guided content';

    #[Test]
    public function exposes_only_allowlisted_capabilities_including_destructive(): void
    {
        $registry = new CapabilityScopedToolRegistry($this->populatedInner(), [self::WRITE_CAP]);

        $names = $this->names($registry);
        // The destructive write tool AND the read tool on the allowlist are visible.
        self::assertContains('wf_record', $names);
        self::assertContains('wf_get', $names);
        // Off-allowlist tools (whatever their destructive flag) are absent.
        self::assertNotContains('entity_read', $names);
        self::assertNotContains('bimaaji_mutate', $names);
    }

    #[Test]
    public function off_tier_tools_are_hidden_as_if_unregistered(): void
    {
        $registry = new CapabilityScopedToolRegistry($this->populatedInner(), [self::WRITE_CAP]);

        self::assertTrue($registry->has('wf_record'));
        self::assertSame('wf_record', $registry->get('wf_record')->name);

        self::assertFalse($registry->has('entity_read'));
        $this->expectException(ToolNotFoundException::class);
        $registry->get('entity_read');
    }

    #[Test]
    public function registering_an_off_tier_tool_is_refused(): void
    {
        $registry = new CapabilityScopedToolRegistry($this->populatedInner(), [self::WRITE_CAP]);

        $this->expectException(\LogicException::class);
        $registry->register($this->makeTool('intruder', 'tool.entity.delete', destructive: true));
    }

    #[Test]
    public function the_same_destructive_tool_is_hidden_on_the_public_read_only_surface(): void
    {
        $inner = $this->populatedInner();

        // C-001: the public read-only surface never shows the destructive write tool…
        $publicNames = $this->names(new ReadOnlyToolRegistry($inner, PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES));
        self::assertNotContains('wf_record', $publicNames, 'A destructive tool must never appear on the public surface.');

        // …while the authenticated write tier does expose it.
        $writeNames = $this->names(new CapabilityScopedToolRegistry($inner, [self::WRITE_CAP]));
        self::assertContains('wf_record', $writeNames);
    }

    private function populatedInner(): ToolRegistryInterface
    {
        return $this->stubRegistry([
            $this->makeTool('wf_record', self::WRITE_CAP, destructive: true),
            $this->makeTool('wf_get', self::WRITE_CAP, destructive: false),
            $this->makeTool('entity_read', 'tool.entity.read', destructive: false),
            $this->makeTool('bimaaji_mutate', 'bimaaji.mutate', destructive: true),
        ]);
    }

    /** @return list<string> */
    private function names(ToolRegistryInterface $registry): array
    {
        $names = [];
        foreach ($registry->all() as $tool) {
            $names[] = $tool->name;
        }

        return $names;
    }

    /** @param list<AgentTool> $tools */
    private function stubRegistry(array $tools): ToolRegistryInterface
    {
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
                return $this->map[$name] ?? throw ToolNotFoundException::forName($name);
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

    private function makeTool(string $name, string $capability, bool $destructive): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'fixture';
            }
        };

        return new AgentTool(
            name: $name,
            capability: $capability,
            destructive: $destructive,
            dryRunSupported: false,
            category: 'test',
            inputSchema: $impl->inputSchema(),
            impl: $impl,
        );
    }
}
