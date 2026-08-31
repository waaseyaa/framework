<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Registry\CapabilityScopedToolRegistry;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\ArrayToolRegistry;
use Waaseyaa\AI\Tools\Tests\Support\Dispatch\ScriptedTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;

/**
 * The capability-scoped visibility filter, at the layer that now owns it
 * (#2657, ADR-022 Q-3). `Waaseyaa\Mcp\CapabilityScopedToolRegistry` delegates
 * here, so these are the assertions both consumers rest on.
 *
 * Narrowing is structural, not advisory: an off-tier tool is *absent*, not
 * merely denied. It never appears in `all()`, and `get()`/`has()` behave as if
 * it were unregistered — so "not registered" and "not yours" are
 * indistinguishable to a caller probing the surface.
 */
#[CoversClass(CapabilityScopedToolRegistry::class)]
final class CapabilityScopedToolRegistryTest extends TestCase
{
    #[Test]
    public function only_allowlisted_capabilities_are_visible_destructive_included(): void
    {
        // The dual of the read-only registry: this tier exposes exactly the
        // capability set it is for, destructive tools included, and nothing
        // else — so an authenticated tier never surfaces the whole destructive
        // catalogue.
        $registry = new CapabilityScopedToolRegistry($this->catalogue(), ['trail.write']);

        self::assertSame(['trail_publish'], $this->visible($registry));
        self::assertTrue($registry->has('trail_publish'));
        self::assertFalse($registry->has('bimaaji_introspect_graph'));
    }

    #[Test]
    public function an_empty_allowlist_exposes_nothing(): void
    {
        // The fail-closed shape a scopeless credential gets when this registry
        // narrows a request to its token scopes.
        self::assertSame([], $this->visible(new CapabilityScopedToolRegistry($this->catalogue(), [])));
    }

    #[Test]
    public function a_blocked_tool_name_is_withheld_even_when_its_capability_is_allowlisted(): void
    {
        // How a network tier enforces a narrower structural policy than the
        // embedded agent catalogue — the write tier's block on generic entity
        // mutations is exactly this.
        $registry = new CapabilityScopedToolRegistry(
            $this->catalogue(),
            ['trail.write', 'bimaaji.read'],
            ['trail_publish'],
        );

        self::assertSame(['bimaaji_introspect_graph'], $this->visible($registry));
    }

    #[Test]
    public function an_off_tier_tool_is_hidden_behind_the_unregistered_error(): void
    {
        $registry = new CapabilityScopedToolRegistry($this->catalogue(), ['trail.write']);

        $this->expectException(ToolNotFoundException::class);
        $registry->get('bimaaji_introspect_graph');
    }

    #[Test]
    public function registering_an_off_tier_tool_is_refused_loudly(): void
    {
        // A caller trying to expose an off-tier tool here is a programming
        // error, not a runtime condition, so it is rejected rather than
        // silently dropped.
        $registry = new CapabilityScopedToolRegistry(new ArrayToolRegistry(), ['trail.write']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/is not on the allowlist/');
        $registry->register($this->tool('bimaaji_introspect_graph', 'bimaaji.read'));
    }

    #[Test]
    public function an_allowlisted_tool_registers_through_to_the_inner_catalogue(): void
    {
        $inner = new ArrayToolRegistry();
        new CapabilityScopedToolRegistry($inner, ['trail.write'])
            ->register($this->tool('trail_publish', 'trail.write'));

        self::assertTrue($inner->has('trail_publish'));
    }

    #[Test]
    public function has_is_false_for_a_name_the_inner_catalogue_does_not_hold(): void
    {
        self::assertFalse(
            new CapabilityScopedToolRegistry($this->catalogue(), ['trail.write'])->has('never_registered'),
        );
    }

    private function catalogue(): ArrayToolRegistry
    {
        return new ArrayToolRegistry([
            $this->tool('bimaaji_introspect_graph', 'bimaaji.read'),
            $this->tool('trail_publish', 'trail.write', destructive: true),
        ]);
    }

    /** @return list<string> */
    private function visible(CapabilityScopedToolRegistry $registry): array
    {
        return array_map(
            static fn(AgentTool $t): string => $t->name,
            iterator_to_array($registry->all(), false),
        );
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
                static fn(array $a): AgentToolResult => AgentToolResult::success([['type' => 'text', 'text' => 'ok']]),
            ),
        );
    }
}
