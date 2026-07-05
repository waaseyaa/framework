<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\Entity\EntityCreateTool;
use Waaseyaa\AI\Tools\Entity\EntityDeleteTool;
use Waaseyaa\AI\Tools\Entity\EntityListTool;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\AI\Tools\Entity\EntitySearchTool;
use Waaseyaa\AI\Tools\Entity\EntityUpdateTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Discovery\PackageManifest;

/**
 * C-12 (security): per-entity access enforcement must be fail-closed once the
 * registry has stamped enforcement on a tool. The dead-code bug was the
 * inverse: with no handler the per-entity guards silently ALLOWED. These tests
 * pin that, when enforcement is required but no handler is attached (a wiring
 * gap), the read / update / delete / create / list / search paths all DENY —
 * an agent can neither read, mutate, nor enumerate an entity it must not see.
 *
 * The enforcement flag is set the way production sets it: the
 * {@see AttributeToolRegistry} is constructed with an access-handler resolver
 * that (here) resolves to null, simulating a handler that failed to resolve.
 */
#[CoversClass(EntityListTool::class)]
#[CoversClass(EntitySearchTool::class)]
#[CoversClass(EntityReadTool::class)]
#[CoversClass(EntityUpdateTool::class)]
#[CoversClass(EntityDeleteTool::class)]
#[CoversClass(EntityCreateTool::class)]
#[CoversClass(AttributeToolRegistry::class)]
final class EntityToolFailClosedTest extends TestCase
{
    private InMemoryToolRepository $repo;
    private SingleTypeEntityTypeManager $etm;

    protected function setUp(): void
    {
        $this->repo = new InMemoryToolRepository();
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'classified secret']));
        $type = new EntityType(
            id: 'tool_test',
            label: 'Tool Test',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $this->etm = new SingleTypeEntityTypeManager($type, $this->repo);
    }

    /**
     * Build a tool through the registry exactly as production does, but with an
     * access-handler resolver that yields null — modelling a handler that could
     * not be resolved. Enforcement is stamped; no handler is attached.
     *
     * @param class-string $toolClass
     */
    private function enforcedButHandlerless(string $toolClass, object $tool, string $name, string $capability): object
    {
        $manifest = new PackageManifest(
            agentTools: [[
                'class' => $toolClass,
                'name' => $name,
                'capability' => $capability,
                'destructive' => false,
                'dry_run_supported' => true,
                'category' => 'entity',
            ]],
        );
        $registry = new AttributeToolRegistry(
            manifest: $manifest,
            container: $this->container([$toolClass => $tool]),
            // Resolver present (=> enforcement required) but resolves to null.
            accessHandlerResolver: static fn(): ?object => null,
        );

        return $registry->get($name)->impl;
    }

    #[Test]
    public function read_is_denied_when_enforcement_is_required_but_no_handler_resolved(): void
    {
        $tool = $this->enforcedButHandlerless(
            EntityReadTool::class,
            new EntityReadTool($this->etm),
            'entity.read',
            'tool.entity.read',
        );

        $result = $tool->execute(['entity_type' => 'tool_test', 'id' => '1'], $this->account(['tool.entity.read']));

        $this->assertTrue($result->isError);
        // R8-c: read still fails closed without a resolvable handler, but on
        // this anonymous-reachable read tier the refusal collapses to the SAME
        // not-found error an absent id returns (indistinguishable from absent),
        // rather than a distinguishable 'forbidden' — no existence oracle. The
        // entity data is still withheld (isError=true), which is the fail-closed
        // guarantee this test pins.
        $this->assertSame('entity.read: tool_test/1 not found', $result->summary, 'read must fail closed (indistinguishably) without a resolvable handler');
    }

    #[Test]
    public function update_is_denied_when_enforcement_is_required_but_no_handler_resolved(): void
    {
        $tool = $this->enforcedButHandlerless(
            EntityUpdateTool::class,
            new EntityUpdateTool($this->etm),
            'entity.update',
            'tool.entity.update',
        );

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'New']],
            $this->account(['tool.entity.update']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame('forbidden', $result->summary);
        $this->assertSame([], $this->repo->saved, 'nothing was written');
    }

    #[Test]
    public function delete_is_denied_when_enforcement_is_required_but_no_handler_resolved(): void
    {
        $tool = $this->enforcedButHandlerless(
            EntityDeleteTool::class,
            new EntityDeleteTool($this->etm),
            'entity.delete',
            'tool.entity.delete',
        );

        $result = $tool->execute(['entity_type' => 'tool_test', 'id' => '1'], $this->account(['tool.entity.delete']));

        $this->assertTrue($result->isError);
        $this->assertSame('forbidden', $result->summary);
        $this->assertSame([], $this->repo->deleted, 'nothing was deleted');
    }

    #[Test]
    public function create_is_denied_when_enforcement_is_required_but_no_handler_resolved(): void
    {
        $tool = $this->enforcedButHandlerless(
            EntityCreateTool::class,
            new EntityCreateTool($this->etm),
            'entity.create',
            'tool.entity.create',
        );

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'values' => ['title' => 'Made']],
            $this->account(['tool.entity.create']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame('forbidden', $result->summary);
        $this->assertSame([], $this->repo->saved, 'nothing was created');
    }

    #[Test]
    public function list_drops_every_entity_when_enforcement_is_required_but_no_handler_resolved(): void
    {
        $tool = $this->enforcedButHandlerless(
            EntityListTool::class,
            new EntityListTool($this->etm),
            'entity.list',
            'tool.entity.list',
        );

        $result = $tool->execute(['entity_type' => 'tool_test'], $this->account(['tool.entity.list']));

        // The enumeration succeeds structurally but yields nothing: a wiring gap
        // must not leak ids/labels/existence. Fail closed = empty set.
        $this->assertFalse($result->isError);
        $this->assertSame(0, $result->content[0]['data']['count'] ?? null);
    }

    #[Test]
    public function search_drops_every_entity_when_enforcement_is_required_but_no_handler_resolved(): void
    {
        $tool = $this->enforcedButHandlerless(
            EntitySearchTool::class,
            new EntitySearchTool($this->etm),
            'entity.search',
            'tool.entity.search',
        );

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'query' => 'secret'],
            $this->account(['tool.entity.search']),
        );

        $this->assertFalse($result->isError);
        $this->assertSame(0, $result->content[0]['data']['count'] ?? null);
    }

    #[Test]
    public function a_bare_tool_without_enforcement_stays_capability_only(): void
    {
        // No registry, no resolver: the historical capability-only contract is
        // preserved — the per-entity guard is a no-op (allow) so a read works.
        $tool = new EntityReadTool($this->etm);

        $result = $tool->execute(['entity_type' => 'tool_test', 'id' => '1'], $this->account(['tool.entity.read']));

        $this->assertFalse($result->isError, 'capability-only construction must keep allowing');
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
