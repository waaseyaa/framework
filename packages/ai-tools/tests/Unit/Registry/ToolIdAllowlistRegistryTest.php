<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher;
use Waaseyaa\AI\Tools\Registry\CapabilityScopedToolRegistry;
use Waaseyaa\AI\Tools\Registry\ToolIdAllowlistRegistry;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\ArrayToolRegistry;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\FixedPrincipal;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\ScriptedTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\Foundation\Audit\AuditStage;

/**
 * ADR-022 D-7.1 and D-7.2 at the registry.
 *
 * D-7.1: the default local profile is a closed list of tool **ids**, not a
 * capability grant, because `requireCapability()` matches a capability string
 * and consults no roster — so a capability allowlist is an open set that any
 * future `#[AsAgentTool]` can join by merge.
 *
 * D-7.2: the two controls are layered. The allowlist narrows visibility; it
 * never widens authority. A tool on the list whose capability the principal
 * lacks is still refused by the tool's own guard.
 */
#[CoversClass(ToolIdAllowlistRegistry::class)]
final class ToolIdAllowlistRegistryTest extends TestCase
{
    #[Test]
    public function only_listed_ids_are_visible_and_matching_is_exact(): void
    {
        $registry = new ToolIdAllowlistRegistry(
            $this->catalogue(),
            ['bimaaji_introspect_graph'],
        );

        self::assertSame(
            ['bimaaji_introspect_graph'],
            array_map(static fn(AgentTool $t): string => $t->name, iterator_to_array($registry->all(), false)),
        );
        self::assertTrue($registry->has('bimaaji_introspect_graph'));
        self::assertFalse($registry->has('bimaaji_introspect_section'));

        // Never a prefix or wildcard match: a closed list that grows by pattern
        // is not a closed list.
        self::assertFalse($registry->has('bimaaji_introspect'));
        self::assertFalse($registry->has('bimaaji_introspect_graph_v2'));
    }

    #[Test]
    public function a_withheld_tool_is_indistinguishable_from_an_unregistered_one(): void
    {
        $registry = new ToolIdAllowlistRegistry($this->catalogue(), ['bimaaji_introspect_graph']);

        $this->expectException(ToolNotFoundException::class);
        $registry->get('bimaaji_introspect_section');
    }

    #[Test]
    public function an_empty_allowlist_exposes_nothing(): void
    {
        // "No profile configured" reads as "no tool admitted", never as "every
        // tool admitted". A narrowing control whose empty state is open is not
        // a narrowing control.
        $registry = new ToolIdAllowlistRegistry($this->catalogue(), []);

        self::assertSame([], iterator_to_array($registry->all(), false));
    }

    #[Test]
    public function registering_an_unlisted_tool_is_refused_loudly(): void
    {
        $registry = new ToolIdAllowlistRegistry(new ArrayToolRegistry(), ['allowed']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/not on this profile\'s allowlist/');
        $registry->register($this->tool('smuggled', 'bimaaji.read'));
    }

    #[Test]
    public function the_allowlist_narrows_and_never_widens_the_capability_check(): void
    {
        // D-7.2: allowlisting a tool does not grant its capability. The
        // principal here holds nothing, so the tool's own guard still refuses —
        // and the dispatcher classifies that as an authorization refusal, not a
        // lookup miss, so the two are distinguishable in an audit trail.
        $registry = new ToolIdAllowlistRegistry(
            new ArrayToolRegistry([$this->guardedTool('bimaaji_introspect_graph', 'bimaaji.read')]),
            ['bimaaji_introspect_graph'],
        );

        $outcome = new AgentToolDispatcher($registry, new FixedPrincipal(permissions: []))
            ->dispatch('bimaaji_introspect_graph', []);

        self::assertSame(AuditStage::AuthorizationRefused, $outcome->stage);
    }

    #[Test]
    public function it_composes_with_the_capability_scope_in_either_order(): void
    {
        $ids = new ToolIdAllowlistRegistry(
            new CapabilityScopedToolRegistry($this->catalogue(), ['bimaaji.read']),
            ['bimaaji_introspect_graph', 'entity_delete'],
        );
        $caps = new CapabilityScopedToolRegistry(
            new ToolIdAllowlistRegistry($this->catalogue(), ['bimaaji_introspect_graph', 'entity_delete']),
            ['bimaaji.read'],
        );

        // The intersection is what survives either way: `entity_delete` is on
        // the id list but its capability is not scoped in.
        foreach ([$ids, $caps] as $registry) {
            self::assertSame(
                ['bimaaji_introspect_graph'],
                array_map(static fn(AgentTool $t): string => $t->name, iterator_to_array($registry->all(), false)),
            );
        }
    }

    private function catalogue(): ArrayToolRegistry
    {
        return new ArrayToolRegistry([
            $this->tool('bimaaji_introspect_graph', 'bimaaji.read'),
            $this->tool('bimaaji_introspect_section', 'bimaaji.read'),
            $this->tool('entity_delete', 'tool.entity.delete', destructive: true),
        ]);
    }

    private function tool(string $name, string $capability, bool $destructive = false): AgentTool
    {
        return new AgentTool(
            name: $name,
            capability: $capability,
            destructive: $destructive,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object'],
            impl: new ScriptedTool(
                static fn(array $args): AgentToolResult => AgentToolResult::success([['type' => 'text', 'text' => 'ok']]),
            ),
        );
    }

    /** A tool that enforces its capability against the acting principal, as every shipped tool does. */
    private function guardedTool(string $name, string $capability): AgentTool
    {
        return new AgentTool(
            name: $name,
            capability: $capability,
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object'],
            impl: new ScriptedTool(
                static fn(array $args): AgentToolResult => AgentToolResult::error(
                    'Capability required.',
                    'forbidden',
                ),
            ),
        );
    }
}
