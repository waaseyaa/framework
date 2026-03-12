<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Rpc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mcp\Rpc\ResponseFormatter;
use Waaseyaa\Mcp\Rpc\ToolIntrospector;

#[CoversClass(ToolIntrospector::class)]
final class ToolIntrospectorTest extends TestCase
{
    #[Test]
    public function diagnosticsDescriptorReturnsKnownTool(): void
    {
        $introspector = new ToolIntrospector(new ResponseFormatter());

        $descriptor = $introspector->diagnosticsDescriptor('search_entities');

        self::assertSame('toolSearchEntities', $descriptor['handler']);
        self::assertSame('semantic_read', $descriptor['category']);
        self::assertContains('mcp_read', $descriptor['cache_tags']);
    }

    #[Test]
    public function diagnosticsDescriptorReturnsDefaultForUnknownTool(): void
    {
        $introspector = new ToolIntrospector(new ResponseFormatter());

        $descriptor = $introspector->diagnosticsDescriptor('nonexistent_tool');

        self::assertSame('unknown', $descriptor['handler']);
        self::assertSame('unknown', $descriptor['category']);
        self::assertContains('unknown_tool', $descriptor['failure_modes']);
    }

    #[Test]
    public function extensionsForToolReturnsEmptyWhenNoRegistrations(): void
    {
        $introspector = new ToolIntrospector(new ResponseFormatter());

        $extensions = $introspector->extensionsForTool('search_entities', 'search_entities');

        self::assertSame(0, $extensions['count']);
        self::assertSame([], $extensions['registered']);
        self::assertSame([], $extensions['execution_path_hooks']);
    }

    #[Test]
    public function extensionsForToolMatchesApplicableRegistration(): void
    {
        $registrations = [
            [
                'id' => 'test_ext',
                'label' => 'Test Extension',
                'tools' => ['search_entities'],
                'hooks' => ['before_tool_call'],
            ],
        ];
        $introspector = new ToolIntrospector(new ResponseFormatter(), $registrations);

        $extensions = $introspector->extensionsForTool('search_entities', 'search_entities');

        self::assertSame(1, $extensions['count']);
        self::assertSame('test_ext', $extensions['registered'][0]['id']);
        self::assertSame(['extensions:before_tool_call'], $extensions['execution_path_hooks']);
    }

    #[Test]
    public function extensionsForToolSkipsNonApplicableRegistration(): void
    {
        $registrations = [
            [
                'id' => 'other_ext',
                'label' => 'Other Extension',
                'tools' => ['get_entity'],
                'hooks' => ['before_tool_call'],
            ],
        ];
        $introspector = new ToolIntrospector(new ResponseFormatter(), $registrations);

        $extensions = $introspector->extensionsForTool('search_entities', 'search_entities');

        self::assertSame(0, $extensions['count']);
    }

    #[Test]
    public function extensionsForToolAppliesGlobalRegistration(): void
    {
        $registrations = [
            [
                'id' => 'global_ext',
                'label' => 'Global Extension',
                'tools' => [],
            ],
        ];
        $introspector = new ToolIntrospector(new ResponseFormatter(), $registrations);

        $extensions = $introspector->extensionsForTool('search_entities', 'search_entities');

        self::assertSame(1, $extensions['count']);
        self::assertSame('global_ext', $extensions['registered'][0]['id']);
        // Default hooks should be applied
        self::assertContains('after_tool_result_meta', $extensions['registered'][0]['hooks']);
        self::assertContains('before_tool_call', $extensions['registered'][0]['hooks']);
    }

    #[Test]
    public function extensionsForToolSkipsInvalidRegistrations(): void
    {
        $registrations = [
            ['id' => '', 'tools' => []],
            'not_an_array',
            ['plugin_id' => 'valid_ext', 'tools' => []],
        ];
        $introspector = new ToolIntrospector(new ResponseFormatter(), $registrations);

        $extensions = $introspector->extensionsForTool('search_entities', 'search_entities');

        self::assertSame(1, $extensions['count']);
        self::assertSame('valid_ext', $extensions['registered'][0]['id']);
    }

    #[Test]
    public function diagnosticsDescriptorCoversAllEditorialTools(): void
    {
        $introspector = new ToolIntrospector(new ResponseFormatter());

        foreach (['editorial_transition', 'editorial_validate', 'editorial_publish', 'editorial_archive'] as $tool) {
            $descriptor = $introspector->diagnosticsDescriptor($tool);
            self::assertStringStartsWith('editorial_', $descriptor['category'], "Tool {$tool} should have editorial category");
            self::assertSame('workflow_transition_enforced', $descriptor['workflow_policy']);
        }
    }
}
