<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Catalogue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolContext;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\Foundation\Discovery\PackageManifest;

#[CoversClass(AttributeToolRegistry::class)]
final class AttributeToolRegistryTest extends TestCase
{
    #[Test]
    public function hydrates_tools_from_manifest_section(): void
    {
        $manifest = new PackageManifest(
            agentTools: [
                [
                    'class' => AttributeToolRegistryTestFixture::class,
                    'name' => 'fixture.tool',
                    'capability' => 'tool.fixture',
                    'destructive' => false,
                    'dry_run_supported' => false,
                    'category' => 'fixture',
                ],
            ],
        );

        $registry = new AttributeToolRegistry(
            manifest: $manifest,
            container: $this->makeContainer([
                AttributeToolRegistryTestFixture::class => new AttributeToolRegistryTestFixture(),
            ]),
        );

        self::assertTrue($registry->has('fixture.tool'));
        $tool = $registry->get('fixture.tool');
        self::assertInstanceOf(AgentTool::class, $tool);
        self::assertSame('tool.fixture', $tool->capability);
        self::assertSame('fixture', $tool->category);
        self::assertInstanceOf(AttributeToolRegistryTestFixture::class, $tool->impl);
    }

    #[Test]
    public function get_throws_for_unknown_tool(): void
    {
        $registry = new AttributeToolRegistry(
            manifest: new PackageManifest(),
            container: $this->makeContainer([]),
        );

        $this->expectException(ToolNotFoundException::class);
        $registry->get('never.registered');
    }

    #[Test]
    public function manually_registered_tools_win_over_discovery_collisions(): void
    {
        $manifest = new PackageManifest(
            agentTools: [
                [
                    'class' => AttributeToolRegistryTestFixture::class,
                    'name' => 'fixture.tool',
                    'capability' => 'tool.fixture',
                    'destructive' => false,
                    'dry_run_supported' => false,
                    'category' => 'fixture',
                ],
            ],
        );
        $registry = new AttributeToolRegistry(
            manifest: $manifest,
            container: $this->makeContainer([
                AttributeToolRegistryTestFixture::class => new AttributeToolRegistryTestFixture(),
            ]),
        );

        $override = new AgentTool(
            name: 'fixture.tool',
            capability: 'tool.fixture',
            destructive: true,
            dryRunSupported: false,
            category: 'override',
            inputSchema: [],
            impl: new AttributeToolRegistryTestFixture(),
        );
        $registry->register($override);

        $resolved = $registry->get('fixture.tool');
        self::assertSame('override', $resolved->category, 'Manually-registered tool must win.');
    }

    /**
     * @param array<class-string, object> $bindings
     */
    private function makeContainer(array $bindings): ContainerInterface
    {
        return new class ($bindings) implements ContainerInterface {
            /**
             * @param array<class-string, object> $bindings
             */
            public function __construct(private readonly array $bindings) {}

            public function get(string $id): object
            {
                if (!isset($this->bindings[$id])) {
                    throw new \RuntimeException("No binding: {$id}");
                }
                return $this->bindings[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->bindings[$id]);
            }
        };
    }
}

/**
 * Fixture exercising the AttributeToolRegistry hydration path.
 *
 * @internal
 */
#[AsAgentTool(name: 'fixture.tool', capability: 'tool.fixture', category: 'fixture')]
final class AttributeToolRegistryTestFixture extends AbstractAgentTool implements AgentToolInterface
{
    public function description(): string
    {
        return 'fixture';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        return AgentToolResult::success([['type' => 'text', 'text' => 'fixture executed']]);
    }
}
