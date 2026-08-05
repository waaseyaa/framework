<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\AiToolsServiceProvider;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\AI\Tools\ProvidesAgentToolsInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * C-12 (security): the production wiring — not a hand-built tool — actually
 * attaches the kernel {@see EntityAccessHandler} to every stock entity tool the
 * registry hydrates. This drives {@see AiToolsServiceProvider::register()} with
 * a kernel-services bus that exposes the handler (exactly as
 * {@see \Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices}
 * does after access-policy discovery), resolves the registry the provider
 * builds, and proves a forbidden entity is refused through it.
 */
#[CoversClass(AiToolsServiceProvider::class)]
#[CoversClass(AttributeToolRegistry::class)]
final class AiToolsServiceProviderWiringTest extends TestCase
{
    #[Test]
    public function application_providers_contribute_tools_before_first_registry_use(): void
    {
        $provider = new AiToolsServiceProvider();
        $provider->withAgentToolProviders([
            new class implements ProvidesAgentToolsInterface {
                public function registerAgentTools(ToolRegistryInterface $registry): void
                {
                    $registry->register(new \Waaseyaa\AI\Tools\AgentTool(
                        name: 'app.example',
                        capability: 'app.example',
                        destructive: false,
                        dryRunSupported: false,
                        category: 'application',
                        inputSchema: ['type' => 'object'],
                        impl: new class implements \Waaseyaa\AI\Tools\AgentToolInterface {
                            public function description(): string { return 'Example application tool.'; }
                            public function inputSchema(): array { return ['type' => 'object']; }
                            public function argumentsForAudit(array $arguments): array { return $arguments; }
                            public function execute(array $arguments, AccountInterface $account): \Waaseyaa\AI\Tools\AgentToolResult
                            {
                                return \Waaseyaa\AI\Tools\AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
                            }
                            public function dryRun(array $arguments, AccountInterface $account): \Waaseyaa\AI\Tools\AgentToolResult
                            {
                                return $this->execute($arguments, $account);
                            }
                        },
                    ));
                }
            },
        ]);
        $provider->register();

        $registry = $provider->resolve(ToolRegistryInterface::class);

        $this->assertInstanceOf(ToolRegistryInterface::class, $registry);
        $this->assertTrue($registry->has('app.example'));
    }

    #[Test]
    public function content_search_is_registered_without_resolving_its_database_backed_provider(): void
    {
        $searchResolutions = 0;
        $catalogueResolutions = 0;
        $manifest = new PackageManifest();
        $container = $this->container([]);
        $handler = new EntityAccessHandler([]);
        $base = $this->bus($manifest, $container, $handler);
        $bus = new class($base, $searchResolutions, $catalogueResolutions) implements KernelServicesInterface {
            public function __construct(
                private readonly KernelServicesInterface $base,
                private int &$searchResolutions,
                private int &$catalogueResolutions,
            ) {}

            public function get(string $abstract): ?object
            {
                if ($abstract === 'Waaseyaa\\Search\\SearchProviderInterface') {
                    ++$this->searchResolutions;
                    throw new \RuntimeException('must stay lazy during tools/list');
                }
                if ($abstract === 'Waaseyaa\\Search\\SearchContentCatalogueInterface') {
                    ++$this->catalogueResolutions;
                    throw new \RuntimeException('must stay lazy during resource discovery');
                }

                return $this->base->get($abstract);
            }
        };
        $provider = new AiToolsServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();

        $registry = $provider->resolve(ToolRegistryInterface::class);
        $resources = $provider->resolve(\Waaseyaa\AI\Tools\Resource\ContentResourceRegistry::class);
        $tool = $registry->get('content.search');

        self::assertSame(0, $searchResolutions);
        self::assertSame(0, $catalogueResolutions);
        self::assertTrue($resources->hasProviders());
        self::assertSame('tool.content.search', $tool->capability);
        self::assertFalse($tool->destructive);
        self::assertTrue($tool->idempotent);
        self::assertFalse($tool->openWorld);
        self::assertSame('object', $tool->outputSchema['type'] ?? null);
    }

    #[Test]
    public function the_provider_wires_the_kernel_access_handler_onto_hydrated_tools(): void
    {
        $repo = new InMemoryToolRepository();
        $repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'classified']));
        $type = new EntityType(
            id: 'tool_test',
            label: 'Tool Test',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $etm = new SingleTypeEntityTypeManager($type, $repo);

        $manifest = new PackageManifest(
            agentTools: [[
                'class' => EntityReadTool::class,
                'name' => 'entity.read',
                'capability' => 'tool.entity.read',
                'destructive' => false,
                'dry_run_supported' => true,
                'category' => 'entity',
            ]],
        );
        $container = $this->container([EntityReadTool::class => new EntityReadTool($etm)]);
        // A handler that forbids 'view' on every tool_test entity.
        $handler = new EntityAccessHandler([$this->forbiddingViewPolicy('tool_test')]);

        $provider = new AiToolsServiceProvider();
        $provider->setKernelServices($this->bus($manifest, $container, $handler));
        $provider->register();

        $registry = $provider->resolve(ToolRegistryInterface::class);
        $this->assertInstanceOf(ToolRegistryInterface::class, $registry);

        $result = $registry->get('entity.read')->impl->execute(
            ['entity_type' => 'tool_test', 'id' => '1'],
            $this->account(['tool.entity.read']),
        );

        $this->assertTrue($result->isError, 'the provider-wired handler must refuse a forbidden read');
        // R8-c: a view-forbidden read on the anonymous-reachable entity.read
        // tool now collapses to the SAME not-found error an absent id returns,
        // so an anonymous caller cannot tell "forbidden" from "absent". The
        // wiring is still proven: without a wired handler (capability-only) the
        // read would SUCCEED and return the entity, so isError=true confirms the
        // handler is enforcing.
        $this->assertSame('entity.read: tool_test/1 not found', $result->summary);
    }

    private function bus(PackageManifest $manifest, ContainerInterface $container, EntityAccessHandler $handler): KernelServicesInterface
    {
        return new class($manifest, $container, $handler) implements KernelServicesInterface {
            public function __construct(
                private readonly PackageManifest $manifest,
                private readonly ContainerInterface $container,
                private readonly EntityAccessHandler $handler,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    PackageManifest::class => $this->manifest,
                    ContainerInterface::class => $this->container,
                    EntityAccessHandler::class => $this->handler,
                    \Waaseyaa\Foundation\Log\LoggerInterface::class => new NullLogger(),
                    default => null,
                };
            }
        };
    }

    private function forbiddingViewPolicy(string $entityTypeId): AccessPolicyInterface
    {
        return new class($entityTypeId) implements AccessPolicyInterface {
            public function __construct(private readonly string $entityTypeId) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'view' ? AccessResult::forbidden('classified') : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };
    }

    /** @param array<class-string, object> $bindings */
    private function container(array $bindings): ContainerInterface
    {
        return new class($bindings) implements ContainerInterface {
            /** @param array<class-string, object> $bindings */
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

    /** @param list<string> $permissions */
    private function account(array $permissions): AccountInterface
    {
        return new class($permissions) implements AccountInterface {
            /** @param list<string> $permissions */
            public function __construct(private readonly array $permissions) {}

            public function id(): int|string
            {
                return 7;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
