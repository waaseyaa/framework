<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

/**
 * Locks the alpha.171 binding-invariant exception contract that downstream
 * regression tests (Groups, Taxonomy, the manifest-sweep integration test, and
 * any future provider tests) rely on for the targetEntityTypeId guarantee.
 *
 * If this test fails, the documented exception class or message format has
 * drifted. Fixing the binding sites elsewhere will not be sufficient — update
 * the dependent tests too.
 *
 * @covers \Waaseyaa\Field\FieldDefinitionRegistry
 */
#[CoversClass(FieldDefinitionRegistry::class)]
final class FieldDefinitionRegistryInvariantTest extends TestCase
{
    #[Test]
    public function registerCoreFields_rejects_empty_target_entity_type_id(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'test_field',
            type: 'string',
            // targetEntityTypeId omitted — defaults to '' which violates the bind invariant.
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^Core field "test_field" declares targetEntityTypeId "" but is being registered against entity type "sample_entity"\.$/',
        );

        $registry->registerCoreFields('sample_entity', ['test_field' => $field]);
    }

    #[Test]
    public function registerCoreFields_rejects_mismatched_target_entity_type_id(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'test_field',
            type: 'string',
            targetEntityTypeId: 'wrong_entity',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^Core field "test_field" declares targetEntityTypeId "wrong_entity" but is being registered against entity type "sample_entity"\.$/',
        );

        $registry->registerCoreFields('sample_entity', ['test_field' => $field]);
    }

    #[Test]
    public function registerBundleFields_rejects_empty_target_entity_type_id(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'bundle_field',
            type: 'string',
            // targetEntityTypeId omitted — defaults to '' which violates the bind invariant.
            targetBundle: 'sample_bundle',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^FieldDefinition "bundle_field" declares targetEntityTypeId "" but is being registered against entity type "sample_entity"\.$/',
        );

        $registry->registerBundleFields('sample_entity', 'sample_bundle', ['bundle_field' => $field]);
    }

    #[Test]
    public function registerBundleFields_rejects_mismatched_target_entity_type_id(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'bundle_field',
            type: 'string',
            targetEntityTypeId: 'wrong_entity',
            targetBundle: 'sample_bundle',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^FieldDefinition "bundle_field" declares targetEntityTypeId "wrong_entity" but is being registered against entity type "sample_entity"\.$/',
        );

        $registry->registerBundleFields('sample_entity', 'sample_bundle', ['bundle_field' => $field]);
    }

    #[Test]
    public function registerCoreFields_accepts_matching_target_entity_type_id(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'good_field',
            type: 'string',
            targetEntityTypeId: 'sample_entity',
        );

        $registry->registerCoreFields('sample_entity', ['good_field' => $field]);

        $registered = $registry->coreFieldsFor('sample_entity');
        self::assertArrayHasKey('good_field', $registered);
        self::assertSame('sample_entity', $registered['good_field']->getTargetEntityTypeId());
    }

    #[Test]
    public function registrationRejectsATypeOutsideThePluginAuthority(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'legacy_map',
            type: 'map',
            targetEntityTypeId: 'sample_entity',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not admitted by the field-type registry');
        $registry->registerCoreFields('sample_entity', ['legacy_map' => $field]);
    }

    #[Test]
    public function registrationRejectsAnEnumWithoutItsRequiredContract(): void
    {
        $registry = new FieldDefinitionRegistry();
        $field = new FieldDefinition(
            name: 'state',
            type: 'enum',
            targetEntityTypeId: 'sample_entity',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not admitted by the field-type registry');
        $registry->registerCoreFields('sample_entity', ['state' => $field]);
    }
}
