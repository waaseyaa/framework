<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\Entity\EntityUpdateTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\Entity\ContentEntityBase;
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
        $registry = new AttributeToolRegistry(
            manifest: $manifest,
            container: $this->container([EntityUpdateTool::class => new EntityUpdateTool($etm)]),
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
