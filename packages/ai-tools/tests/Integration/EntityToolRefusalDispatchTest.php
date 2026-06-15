<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\Entity\EntityListTool;
use Waaseyaa\AI\Tools\Entity\EntityUpdateTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Discovery\PackageManifest;

/**
 * WP03 / T014 (NFR-002 transport witness): the identity-key refusal
 * reaches callers through the real tool dispatch surface — the
 * manifest-hydrated {@see AttributeToolRegistry} resolved by name, the
 * same `registry->get(name)->impl->execute()` path AgentExecutor and the
 * MCP AgentToolRegistryBridge dispatch through.
 */
#[CoversNothing]
final class EntityToolRefusalDispatchTest extends TestCase
{
    #[Test]
    public function dispatching_entity_update_with_a_langcode_value_yields_the_structured_refusal_and_leaves_storage_untouched(): void
    {
        $repo = new InMemoryToolRepository();
        $repo->seed(new TranslatableDispatchEntity([
            'id' => '1',
            'title' => 'Original',
            'langcode' => 'en',
            'default_langcode' => true,
        ]));
        $type = new EntityType(
            id: 'tool_test_translatable',
            label: 'Tool Test (translatable)',
            class: TranslatableDispatchEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
        );
        $etm = new SingleTypeEntityTypeManager($type, $repo);

        // Real discovery shape: the manifest's agent_tools section names the
        // tool class; the registry lazily instantiates it via the container.
        $manifest = new PackageManifest(
            agentTools: [
                [
                    'class' => EntityUpdateTool::class,
                    'name' => 'entity.update',
                    'capability' => 'tool.entity.update',
                    'destructive' => true,
                    'dry_run_supported' => true,
                    'category' => 'entity',
                ],
            ],
        );
        // C-12: the registry is wired with the kernel access handler resolver,
        // exactly as AiToolsServiceProvider does in production. A permissive
        // handler is supplied so the access gate passes and the dispatch
        // reaches the identity-key guard (which runs AFTER the access check).
        $registry = new AttributeToolRegistry(
            manifest: $manifest,
            container: $this->container([EntityUpdateTool::class => new EntityUpdateTool($etm)]),
            accessHandlerResolver: fn(): EntityAccessHandler => $this->permissiveHandler('tool_test_translatable'),
        );

        $tool = $registry->get('entity.update');
        $result = $tool->impl->execute(
            ['entity_type' => 'tool_test_translatable', 'id' => '1', 'values' => ['langcode' => 'xx']],
            $this->account(['tool.entity.update']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(
            'entity.update: refused identity keys: langcode — identity fields cannot be written through this tool',
            $result->content[0]['text'] ?? null,
        );
        $this->assertSame(
            ['error' => 'identity_keys_refused', 'refused_keys' => ['langcode']],
            $result->content[1]['data'] ?? null,
        );

        // The row is untouched in storage: no save, langcode unchanged.
        $this->assertSame([], $repo->saved);
        $row = $repo->find('1');
        $this->assertNotNull($row);
        $this->assertSame('en', $row->get('langcode'));
        $this->assertSame('Original', $row->get('title'));
    }

    #[Test]
    public function dispatching_entity_update_on_a_view_forbidden_entity_refuses_through_the_wired_registry(): void
    {
        // C-12 positive witness: the access handler wired into the registry
        // (production path) actually refuses a forbidden entity on the
        // single-entity update path — not a unit stub on a hand-built tool.
        $repo = new InMemoryToolRepository();
        $repo->seed(new ToolTestEntity(['id' => '9', 'title' => 'classified']));
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
                'class' => EntityUpdateTool::class,
                'name' => 'entity.update',
                'capability' => 'tool.entity.update',
                'destructive' => true,
                'dry_run_supported' => true,
                'category' => 'entity',
            ]],
        );
        $registry = new AttributeToolRegistry(
            manifest: $manifest,
            container: $this->container([EntityUpdateTool::class => new EntityUpdateTool($etm)]),
            accessHandlerResolver: fn(): EntityAccessHandler => $this->forbiddingHandler('tool_test', '9'),
        );

        $result = $registry->get('entity.update')->impl->execute(
            ['entity_type' => 'tool_test', 'id' => '9', 'values' => ['title' => 'New']],
            $this->account(['tool.entity.update']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame('forbidden', $result->summary, 'the wired handler refuses the forbidden update');
        $this->assertSame([], $repo->saved, 'nothing was written');
    }

    #[Test]
    public function dispatching_entity_list_drops_a_view_forbidden_entity_through_the_wired_registry(): void
    {
        // C-12 positive witness on the ENUMERATION path: a forbidden entity must
        // not leak via entity.list when the registry-wired handler forbids it.
        $repo = new InMemoryToolRepository();
        $repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'visible']));
        $repo->seed(new ToolTestEntity(['id' => '9', 'title' => 'classified']));
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
                'class' => EntityListTool::class,
                'name' => 'entity.list',
                'capability' => 'tool.entity.list',
                'destructive' => false,
                'dry_run_supported' => true,
                'category' => 'entity',
            ]],
        );
        $registry = new AttributeToolRegistry(
            manifest: $manifest,
            container: $this->container([EntityListTool::class => new EntityListTool($etm)]),
            // Forbids id '9' only; '1' remains viewable.
            accessHandlerResolver: fn(): EntityAccessHandler => $this->forbiddingHandler('tool_test', '9'),
        );

        $result = $registry->get('entity.list')->impl->execute(
            ['entity_type' => 'tool_test'],
            $this->account(['tool.entity.list']),
        );

        $this->assertFalse($result->isError);
        $ids = array_map(
            static fn(array $i): string => (string) $i['id'],
            $result->content[0]['data']['items'] ?? [],
        );
        $this->assertContains('1', $ids, 'the viewable entity is enumerated');
        $this->assertNotContains('9', $ids, 'the forbidden entity must not leak via enumeration');
    }

    #[Test]
    public function a_registry_without_an_access_resolver_leaves_tools_capability_only(): void
    {
        // Pins that the fix is a no-op for hosts that never wire a handler: the
        // registry built without accessHandlerResolver does NOT stamp
        // enforcement, so a capability-holding account still lists every entity.
        $repo = new InMemoryToolRepository();
        $repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'a']));
        $repo->seed(new ToolTestEntity(['id' => '2', 'title' => 'b']));
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
                'class' => EntityListTool::class,
                'name' => 'entity.list',
                'capability' => 'tool.entity.list',
                'destructive' => false,
                'dry_run_supported' => true,
                'category' => 'entity',
            ]],
        );
        $registry = new AttributeToolRegistry(
            manifest: $manifest,
            container: $this->container([EntityListTool::class => new EntityListTool($etm)]),
        );

        $result = $registry->get('entity.list')->impl->execute(
            ['entity_type' => 'tool_test'],
            $this->account(['tool.entity.list']),
        );

        $this->assertFalse($result->isError);
        $this->assertSame(2, $result->content[0]['data']['count'] ?? null);
    }

    /**
     * Handler that allows every operation on the named type — proves the
     * access gate is consulted yet does not itself refuse.
     */
    private function permissiveHandler(string $entityTypeId): EntityAccessHandler
    {
        return new EntityAccessHandler([$this->policy($entityTypeId, null)]);
    }

    /**
     * Handler that forbids 'view'/'update' on $entityTypeId. When $onlyId is
     * given, only that entity id is forbidden (others are allowed) — used to
     * prove the enumeration path drops just the forbidden row.
     */
    private function forbiddingHandler(string $entityTypeId, ?string $onlyId = null): EntityAccessHandler
    {
        return new EntityAccessHandler([$this->policy($entityTypeId, $onlyId)]);
    }

    /**
     * @param string|null $forbidId null = allow all; a string = forbid only
     *        that id; the sentinel '*' forbids every id of the type.
     */
    private function policy(string $entityTypeId, ?string $forbidId): AccessPolicyInterface
    {
        return new class($entityTypeId, $forbidId) implements AccessPolicyInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly ?string $forbidId,
            ) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($this->forbidId === null) {
                    return AccessResult::allowed();
                }
                $forbidden = $this->forbidId === '*' || (string) $entity->id() === $this->forbidId;

                return $forbidden ? AccessResult::forbidden('classified') : AccessResult::allowed();
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

/**
 * Real translatable entity (ContentEntityBase implements
 * TranslatableInterface) for the dispatch-path refusal witness.
 *
 * @internal
 */
final class TranslatableDispatchEntity extends ContentEntityBase
{
    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'tool_test_translatable', [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
            'langcode' => 'langcode',
            'default_langcode' => 'default_langcode',
        ]);
    }
}
