<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Waaseyaa\AI\Agent\Tool\Bimaaji\GeneratePatchTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectGraphTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool;
use Waaseyaa\AI\Agent\Tool\Bimaaji\ProposeMutationTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;

/**
 * WP01 boot smoke test for the bimaaji-mcp bridge.
 *
 * Asserts the infrastructure required by M3 (`bimaaji-mcp-bridge-01KS5VS8`)
 * is in place: the four SC-004 bimaaji tools shipped by M2
 * (`ai-agent-bimaaji-tools-01KS5VKR`) are discoverable via `#[AsAgentTool]`,
 * implement the agent-tool interface, carry the canonical names + capabilities,
 * have unique names, and can be exposed through the concrete
 * {@see \Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge} adapter.
 *
 * **SC-004 anchor:** `kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/verification.md`
 *
 * The four contracts under test (any deviation breaks SC-004):
 *
 * | FQCN | Name | Capability |
 * |------|------|------------|
 * | `IntrospectGraphTool`   | `bimaaji_introspect_graph`   | `bimaaji.read`   |
 * | `IntrospectSectionTool` | `bimaaji_introspect_section` | `bimaaji.read`   |
 * | `ProposeMutationTool`   | `bimaaji_propose_mutation`   | `bimaaji.mutate` |
 * | `GeneratePatchTool`     | `bimaaji_generate_patch`     | `bimaaji.mutate` |
 *
 * **WP01 audit findings (pinned here so future agents inherit them):**
 *
 *  - Tool registration: `#[AsAgentTool]` + `PackageManifestCompiler` →
 *    `agent_tools` manifest section → lazy hydration by
 *    `\Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry` (bound by
 *    `\Waaseyaa\AI\Tools\AiToolsServiceProvider`).
 *  - MCP-side adapter:
 *    `\Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge` wraps the framework-wide
 *    agent-tool registry and forwards an `AccountInterface` to every
 *    `execute()` call. It implements both `ToolRegistryInterface` and
 *    `ToolExecutorInterface`, so a single instance can be bound to both.
 *  - Name uniqueness: enforced by `AttributeToolRegistry::register()`
 *    (overwrite by name) plus a guard in the hydration loop
 *    (`if (!isset($this->tools[$tool->name]))`). The reflection assertion
 *    below mirrors that contract for the SC-004 surface.
 *  - Production HTTP dispatch: `/mcp` requests flow through the SSR
 *    `AppControllerRouter` to `McpEndpoint::handle`. The legacy foundation
 *    `McpRouter` intercept was retired in this WP — it guarded a literal
 *    `_controller === 'mcp.endpoint'` string that no real route ever sets.
 *
 * **Out of scope for WP01:** the full per-request bridge-construction story
 * (the bridge currently takes `AccountInterface` at construction; production
 * needs the auth-resolved account). End-to-end bridge wiring through
 * `McpEndpoint` is exercised by
 * `tests/Integration/PhaseN/AgentRuntime/McpControllerToolsSharingTest.php`.
 * Per-request account passthrough is the WP03 capability-gating concern.
 *
 * @api
 */
#[CoversNothing]
final class BimaajiMcpBootSmokeTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function sc004ContractProvider(): iterable
    {
        yield 'introspect_graph'   => [IntrospectGraphTool::class,   'bimaaji_introspect_graph',   'bimaaji.read'];
        yield 'introspect_section' => [IntrospectSectionTool::class, 'bimaaji_introspect_section', 'bimaaji.read'];
        yield 'propose_mutation'   => [ProposeMutationTool::class,   'bimaaji_propose_mutation',   'bimaaji.mutate'];
        yield 'generate_patch'     => [GeneratePatchTool::class,     'bimaaji_generate_patch',     'bimaaji.mutate'];
    }

    #[Test]
    public function sc004ToolClassesExist(): void
    {
        foreach (self::sc004ContractProvider() as $row) {
            [$fqcn] = $row;
            self::assertTrue(class_exists($fqcn), "Missing M2 bimaaji tool class: {$fqcn}");
        }
    }

    #[Test]
    public function sc004ToolsImplementAgentToolInterface(): void
    {
        foreach (self::sc004ContractProvider() as $row) {
            [$fqcn] = $row;
            $reflection = new ReflectionClass($fqcn);
            self::assertTrue(
                $reflection->implementsInterface(AgentToolInterface::class),
                "{$fqcn} must implement AgentToolInterface so AttributeToolRegistry can hydrate it.",
            );
        }
    }

    #[Test]
    public function sc004ToolsCarryTheExpectedAsAgentToolAttribute(): void
    {
        foreach (self::sc004ContractProvider() as $row) {
            [$fqcn, $expectedName, $expectedCapability] = $row;

            $attributes = new ReflectionClass($fqcn)->getAttributes(AsAgentTool::class);
            self::assertCount(
                1,
                $attributes,
                "{$fqcn} must carry exactly one #[AsAgentTool] attribute.",
            );

            $attribute = $attributes[0]->newInstance();
            self::assertInstanceOf(AsAgentTool::class, $attribute);
            self::assertSame(
                $expectedName,
                $attribute->name,
                "{$fqcn} declares name '{$attribute->name}' but SC-004 pins '{$expectedName}'.",
            );
            self::assertSame(
                $expectedCapability,
                $attribute->capability,
                "{$fqcn} declares capability '{$attribute->capability}' but SC-004 pins '{$expectedCapability}'.",
            );
        }
    }

    #[Test]
    public function sc004ToolNamesAreUniqueWithinTheSurface(): void
    {
        $names = [];
        foreach (self::sc004ContractProvider() as $row) {
            $names[] = $row[1];
        }

        self::assertSame(
            $names,
            array_values(array_unique($names)),
            'SC-004 tool names must be unique within the bimaaji MCP surface (registry overwrites by name).',
        );
    }

    #[Test]
    public function agentToolRegistryBridgeExposesTheDirectMcpAdapterSurface(): void
    {
        $reflection = new ReflectionClass(AgentToolRegistryBridge::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->hasMethod('getTools'));
        self::assertTrue($reflection->hasMethod('getTool'));
        self::assertTrue($reflection->hasMethod('execute'));
    }
}
