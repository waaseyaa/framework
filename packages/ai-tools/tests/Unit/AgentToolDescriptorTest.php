<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;

/**
 * The MCP descriptor is the only self-description a caller ever sees, so its
 * spec-standard `annotations.destructiveHint` must state the tool's declared
 * destructiveness (#2177 F1 slice B). The hint is advisory display metadata by
 * MCP spec — server-side enforcement (the approval gate) never reads it.
 */
#[CoversClass(AgentTool::class)]
final class AgentToolDescriptorTest extends TestCase
{
    private function tool(bool $destructive): AgentTool
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function description(): string
            {
                return 'described';
            }
        };

        return new AgentTool(
            'fixture.tool',
            'cap',
            $destructive,
            false,
            'content',
            $impl->inputSchema(),
            $impl,
            title: 'Fixture Tool',
            outputSchema: ['type' => 'object'],
            idempotent: true,
            openWorld: false,
        );
    }

    #[Test]
    public function the_descriptor_declares_a_destructive_tool_via_the_spec_standard_annotation(): void
    {
        $descriptor = $this->tool(destructive: true)->toMcpDescriptor();

        self::assertTrue($descriptor['annotations']['destructiveHint'] ?? null);
    }

    #[Test]
    public function the_descriptor_declares_a_non_destructive_tool_via_the_spec_standard_annotation(): void
    {
        $descriptor = $this->tool(destructive: false)->toMcpDescriptor();

        self::assertFalse($descriptor['annotations']['destructiveHint'] ?? null);
        self::assertTrue($descriptor['annotations']['readOnlyHint'] ?? null);
    }

    #[Test]
    public function the_descriptor_keeps_its_established_members(): void
    {
        $descriptor = $this->tool(destructive: true)->toMcpDescriptor();

        self::assertSame('fixture.tool', $descriptor['name']);
        self::assertSame('described', $descriptor['description']);
        self::assertSame(['type' => 'object'], $descriptor['inputSchema']);
        self::assertSame('Fixture Tool', $descriptor['title']);
        self::assertSame(['type' => 'object'], $descriptor['outputSchema']);
        self::assertTrue($descriptor['annotations']['idempotentHint']);
        self::assertFalse($descriptor['annotations']['openWorldHint']);
    }
}
