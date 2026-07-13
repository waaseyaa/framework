<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Write;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Entity\Write\EntityWritePayloadGuard;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

/**
 * CW-v1 option-1 design §5 (PR-4, findings #1/#2): the write-side field
 * allowlist shared by JSON:API, admin-surface, and GraphQL. Modeled on
 * ai-tools' `EntityKeyGuard`, but keyed by payload KEY presence and against
 * the bundle-resolved declared-field set rather than only entity keys.
 */
#[CoversClass(EntityWritePayloadGuard::class)]
final class EntityWritePayloadGuardTest extends TestCase
{
    /**
     * resolveFieldDefinitions() requires the type to be registered — this
     * builds a fresh manager per test and registers the given type.
     */
    private function managerWith(EntityTypeInterface $type, ?FieldDefinitionRegistry $registry = null): EntityTypeManager
    {
        $manager = new EntityTypeManager(new EventDispatcher(), null, null, $registry);
        $manager->registerEntityType($type);

        return $manager;
    }

    #[Test]
    public function declared_base_field_passes(): void
    {
        $definition = TestEntityType::stub(
            'article',
            ['title' => new FieldDefinition(name: 'title', type: 'string')],
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );

        $refused = EntityWritePayloadGuard::refusedKeys($definition, '', ['title'], $this->managerWith($definition));

        self::assertSame([], $refused);
    }

    #[Test]
    public function undeclared_base_column_is_refused(): void
    {
        $definition = TestEntityType::stub(
            'article2',
            ['title' => new FieldDefinition(name: 'title', type: 'string')],
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $refused = EntityWritePayloadGuard::refusedKeys($definition, '', ['not_a_field'], $this->managerWith($definition));

        self::assertSame(['not_a_field'], $refused);
    }

    #[Test]
    public function published_revision_id_is_refused_even_though_it_carries_no_entity_key_kind(): void
    {
        // Findings #1/#2's exact root cause: published_revision_id is a real
        // base column with NO entity-key kind and NO field definition on any
        // shipped entity type — only the literal floor closes this.
        $definition = TestEntityType::stub(
            'node_fixture_a',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
        );

        $refused = EntityWritePayloadGuard::refusedKeys(
            $definition,
            'article',
            ['title', 'published_revision_id'],
            $this->managerWith($definition),
        );

        self::assertSame(['published_revision_id'], $refused);
    }

    #[Test]
    public function revision_id_is_refused_on_the_registered_revision_kind(): void
    {
        $definition = TestEntityType::stub(
            'node_fixture_b',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
        );

        $refused = EntityWritePayloadGuard::refusedKeys($definition, '', ['revision_id'], $this->managerWith($definition));

        self::assertSame(['revision_id'], $refused);
    }

    #[Test]
    public function every_registered_refused_kind_is_refused_regardless_of_declaration(): void
    {
        $definition = TestEntityType::stub(
            'widget_a',
            // Declared as fields too — the identity check must still win.
            [
                'uuid' => new FieldDefinition(name: 'uuid', type: 'string'),
                'vid' => new FieldDefinition(name: 'vid', type: 'integer'),
                'langcode' => new FieldDefinition(name: 'langcode', type: 'string'),
                'default_langcode' => new FieldDefinition(name: 'default_langcode', type: 'boolean'),
            ],
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'revision' => 'vid',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
        );

        $refused = EntityWritePayloadGuard::refusedKeys(
            $definition,
            '',
            ['uuid', 'vid', 'langcode', 'default_langcode'],
            $this->managerWith($definition),
        );

        self::assertSame(['default_langcode', 'langcode', 'uuid', 'vid'], $refused);
    }

    #[Test]
    public function literal_floor_is_refused_even_when_the_kind_is_unregistered(): void
    {
        $definition = TestEntityType::stub('widget_b', [], keys: ['id' => 'id']);

        $refused = EntityWritePayloadGuard::refusedKeys(
            $definition,
            '',
            ['uuid', 'langcode', 'default_langcode', 'revision_id', 'published_revision_id'],
            $this->managerWith($definition),
        );

        self::assertSame(
            ['default_langcode', 'langcode', 'published_revision_id', 'revision_id', 'uuid'],
            $refused,
        );
    }

    #[Test]
    public function label_and_bundle_columns_pass_even_though_undeclared(): void
    {
        $definition = TestEntityType::stub(
            'widget_c',
            [],
            keys: ['id' => 'id', 'label' => 'title', 'bundle' => 'type'],
        );

        $refused = EntityWritePayloadGuard::refusedKeys($definition, '', ['title', 'type'], $this->managerWith($definition));

        self::assertSame([], $refused);
    }

    #[Test]
    public function status_and_workflow_state_pass_when_declared(): void
    {
        // Design §5: status/workflow_state are ordinary declared fields; this
        // guard does not double-gate them (field-level access policy does).
        $definition = TestEntityType::stub(
            'node_fixture_c',
            [
                'status' => new FieldDefinition(name: 'status', type: 'boolean'),
                'workflow_state' => new FieldDefinition(name: 'workflow_state', type: 'string'),
            ],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );

        $refused = EntityWritePayloadGuard::refusedKeys(
            $definition,
            '',
            ['status', 'workflow_state'],
            $this->managerWith($definition),
        );

        self::assertSame([], $refused);
    }

    #[Test]
    public function bundle_scoped_field_passes_via_resolveFieldDefinitions(): void
    {
        $type = TestEntityType::stub(
            'node_fixture_d',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );

        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('node_fixture_d', 'article', [
            'body' => new FieldDefinition(
                name: 'body',
                type: 'text',
                targetEntityTypeId: 'node_fixture_d',
                targetBundle: 'article',
                label: 'Body',
            ),
        ]);

        $manager = $this->managerWith($type, $registry);

        $refused = EntityWritePayloadGuard::refusedKeys($type, 'article', ['body'], $manager);

        self::assertSame([], $refused);
    }

    #[Test]
    public function output_is_sorted_and_deduplicated(): void
    {
        $definition = TestEntityType::stub('widget_d', [], keys: ['id' => 'id']);

        $refused = EntityWritePayloadGuard::refusedKeys(
            $definition,
            '',
            ['published_revision_id', 'uuid', 'published_revision_id', 'langcode'],
            $this->managerWith($definition),
        );

        self::assertSame(['langcode', 'published_revision_id', 'uuid'], $refused);
    }

    #[Test]
    public function empty_payload_yields_empty_list(): void
    {
        $definition = TestEntityType::stub('widget_e', [], keys: ['id' => 'id']);

        self::assertSame([], EntityWritePayloadGuard::refusedKeys($definition, '', [], $this->managerWith($definition)));
    }
}
