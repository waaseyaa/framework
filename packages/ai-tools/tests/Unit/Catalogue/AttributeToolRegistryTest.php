<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Catalogue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\ToolDependencyUnavailableException;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;

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
    public function dependency_unavailable_tools_skip_quietly_while_real_failures_log_error(): void
    {
        // Two tools fail to resolve: one because an optional dependency is absent
        // in this kernel (typed signal), one because of a genuine bug. The set is
        // unchanged (both stay out) — only the log level differs, which is what
        // keeps boot / tools/list logs clean (folds CL-2 / P2-10).
        $manifest = new PackageManifest(
            agentTools: [
                ['class' => UnavailableToolFixture::class, 'name' => 'unavailable.tool', 'capability' => 'x', 'destructive' => false, 'dry_run_supported' => false, 'category' => 'c'],
                ['class' => BrokenToolFixture::class, 'name' => 'broken.tool', 'capability' => 'x', 'destructive' => false, 'dry_run_supported' => false, 'category' => 'c'],
            ],
        );

        $container = new class implements ContainerInterface {
            public function get(string $id): object
            {
                if ($id === UnavailableToolFixture::class) {
                    throw ToolDependencyUnavailableException::forDependency($id, 'RouteCollection');
                }

                throw new \RuntimeException('genuine wiring bug');
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{0: LogLevel, 1: string}> */
            public array $records = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };

        $registry = new AttributeToolRegistry(manifest: $manifest, container: $container, logger: $logger);

        // Both tools are absent from the resolved set.
        self::assertFalse($registry->has('unavailable.tool'));
        self::assertFalse($registry->has('broken.tool'));

        $matching = static fn(LogLevel $level, string $needle): array => array_values(array_filter(
            $logger->records,
            static fn(array $r): bool => $r[0] === $level && str_contains($r[1], $needle),
        ));

        // Absent-dependency tool: debug only, never error (no spam).
        self::assertNotEmpty($matching(LogLevel::DEBUG, 'UnavailableToolFixture'), 'unavailable tool must be a quiet debug skip');
        self::assertEmpty($matching(LogLevel::ERROR, 'UnavailableToolFixture'), 'unavailable tool must NOT log at error');

        // Genuine failure still surfaces at error.
        self::assertNotEmpty($matching(LogLevel::ERROR, 'BrokenToolFixture'), 'a real wiring bug must still log at error');
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

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        return AgentToolResult::success([['type' => 'text', 'text' => 'fixture executed']]);
    }
}

/**
 * Resolves to a typed "dependency unavailable" — the optional-dep-absent case.
 *
 * @internal
 */
final class UnavailableToolFixture {}

/**
 * Resolves with a generic failure — the genuine-bug case.
 *
 * @internal
 */
final class BrokenToolFixture {}
