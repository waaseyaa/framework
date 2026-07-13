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
use Waaseyaa\Entity\Write\EntityWritePayloadGuardResult;
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

    // --- evaluateForUpdate(): echo-tolerant rejection (PR-4 rework) ---
    //
    // Drupal JSON:API parity: a read-modify-write client (the admin SPA's
    // SchemaForm.vue, `formData.value = { ...entityResult.value.attributes }`)
    // echoes EVERY loaded attribute back on PATCH, including the
    // identity/bookkeeping columns FR-008 documents as load-bearing READ
    // attributes (`docs/specs/api-layer.md` "revision_id is a load-bearing
    // read attribute"). A pure echo (submitted === stored) must pass so that
    // round trip does not 422 every edit; a genuinely DIFFERENT value must
    // still be refused (the security core findings #1/#2 close). Echo
    // tolerance applies ONLY to the identity/bookkeeping set — an undeclared
    // field stays hard-refused even when its value happens to match.

    #[Test]
    public function echo_equal_identity_value_is_not_refused_and_is_reported_as_echoed(): void
    {
        $definition = TestEntityType::stub(
            'node_fixture_e1',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            'article',
            ['published_revision_id' => 42],
            $this->managerWith($definition),
            ['published_revision_id' => 42],
        );

        self::assertInstanceOf(EntityWritePayloadGuardResult::class, $result);
        self::assertSame([], $result->refusedKeys);
        self::assertSame(['published_revision_id'], $result->echoedKeys);
    }

    #[Test]
    public function differing_identity_value_is_refused_and_not_echoed(): void
    {
        $definition = TestEntityType::stub(
            'node_fixture_e2',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            'article',
            ['published_revision_id' => 42],
            $this->managerWith($definition),
            ['published_revision_id' => 7],
        );

        self::assertSame(['published_revision_id'], $result->refusedKeys);
        self::assertSame([], $result->echoedKeys);
    }

    #[Test]
    public function value_comparison_is_type_lenient_int_vs_string(): void
    {
        // A JSON-decoded PATCH body int vs. a string-hydrated storage column
        // (or vice versa) must still count as an echo — (string) normalization.
        $definition = TestEntityType::stub(
            'node_fixture_e3',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            'article',
            ['revision_id' => '42'],
            $this->managerWith($definition),
            ['revision_id' => 42],
        );

        self::assertSame([], $result->refusedKeys);
        self::assertSame(['revision_id'], $result->echoedKeys);
    }

    #[Test]
    public function null_submitted_against_null_stored_is_an_echo(): void
    {
        $definition = TestEntityType::stub(
            'node_fixture_e4',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            'article',
            ['published_revision_id' => null],
            $this->managerWith($definition),
            ['published_revision_id' => null],
        );

        self::assertSame([], $result->refusedKeys);
        self::assertSame(['published_revision_id'], $result->echoedKeys);
    }

    #[Test]
    public function null_submitted_against_absent_stored_is_an_echo(): void
    {
        // "absent" (no array key at all in currentValues) is treated the same
        // as an explicit stored null.
        $definition = TestEntityType::stub(
            'node_fixture_e5',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            'article',
            ['published_revision_id' => null],
            $this->managerWith($definition),
            [],
        );

        self::assertSame([], $result->refusedKeys);
        self::assertSame(['published_revision_id'], $result->echoedKeys);
    }

    #[Test]
    public function null_submitted_against_non_null_stored_is_refused_not_echoed(): void
    {
        $definition = TestEntityType::stub(
            'node_fixture_e6',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            'article',
            ['published_revision_id' => null],
            $this->managerWith($definition),
            ['published_revision_id' => 42],
        );

        self::assertSame(['published_revision_id'], $result->refusedKeys);
        self::assertSame([], $result->echoedKeys);
    }

    #[Test]
    public function translatable_shape_langcode_echo_is_tolerated(): void
    {
        // Translatable-shape unit test (rework brief item #4): `langcode` is
        // in LITERAL_FLOOR and typically also the registered `langcode` kind
        // on a translatable entity type. A read-modify-write client that
        // echoes the loaded langcode back unmodified must not be refused.
        $definition = TestEntityType::stub(
            'teaching_fixture',
            [],
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'title', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            '',
            ['langcode' => 'en'],
            $this->managerWith($definition),
            ['langcode' => 'en'],
        );

        self::assertSame([], $result->refusedKeys);
        self::assertSame(['langcode'], $result->echoedKeys);
    }

    #[Test]
    public function translatable_shape_langcode_differing_value_is_refused(): void
    {
        $definition = TestEntityType::stub(
            'teaching_fixture_2',
            [],
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'title', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            '',
            ['langcode' => 'fr'],
            $this->managerWith($definition),
            ['langcode' => 'en'],
        );

        self::assertSame(['langcode'], $result->refusedKeys);
        self::assertSame([], $result->echoedKeys);
    }

    #[Test]
    public function undeclared_field_is_refused_even_when_its_value_matches_the_stored_value(): void
    {
        // Echo tolerance applies ONLY to the identity/bookkeeping set — an
        // undeclared/unknown field is never echo-tolerant, even when its
        // submitted value happens to equal a same-named key in currentValues.
        $definition = TestEntityType::stub(
            'widget_f',
            ['title' => new FieldDefinition(name: 'title', type: 'string')],
            keys: ['id' => 'id', 'label' => 'title'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            '',
            ['not_a_field' => 'same'],
            $this->managerWith($definition),
            ['not_a_field' => 'same'],
        );

        self::assertSame(['not_a_field'], $result->refusedKeys);
        self::assertSame([], $result->echoedKeys);
    }

    #[Test]
    public function declared_field_passes_through_untouched_and_is_never_reported_as_echoed(): void
    {
        // Ordinary declared fields are not identity/bookkeeping columns —
        // they pass (or fail) exactly as refusedKeys() always did, and are
        // never added to echoedKeys (nothing to strip; the apply loop must
        // still receive them so the edit takes effect).
        $definition = TestEntityType::stub(
            'article_e',
            ['title' => new FieldDefinition(name: 'title', type: 'string')],
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );

        $result = EntityWritePayloadGuard::evaluateForUpdate(
            $definition,
            '',
            ['title' => 'Same title'],
            $this->managerWith($definition),
            ['title' => 'Same title'],
        );

        self::assertSame([], $result->refusedKeys);
        self::assertSame([], $result->echoedKeys);
    }

    #[Test]
    public function refusedKeys_is_unaffected_by_currentValues_and_has_no_optional_parameter(): void
    {
        // store() (create) calls refusedKeys() directly and must keep hard
        // refusing — there is no stored value to echo against, and the
        // create surface does not round-trip (rework brief: "store() ...
        // unchanged: hard refuse").
        $definition = TestEntityType::stub(
            'node_fixture_e7',
            [],
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
        );

        $refused = EntityWritePayloadGuard::refusedKeys(
            $definition,
            'article',
            ['published_revision_id'],
            $this->managerWith($definition),
        );

        self::assertSame(['published_revision_id'], $refused);
    }
}
