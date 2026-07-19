<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\Exception\StaleEntityReadLayout;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldReadDefinitionInterface;

final class EntityReadRuntimeCacheTest extends TestCase
{
    #[Test]
    public function immutable_semantic_templates_are_shared_without_sharing_registry_generation_authority(): void
    {
        $before = $this->immutableSemanticTemplateCount();
        $firstRegistry = new FieldDefinitionRegistry();
        $secondRegistry = new FieldDefinitionRegistry();
        foreach ([$firstRegistry, $secondRegistry] as $registry) {
            $registry->registerCoreFields('template_cache_entity', [
                'name' => new FieldDefinition(
                    name: 'name',
                    type: 'string',
                    targetEntityTypeId: 'template_cache_entity',
                    read: FieldReadLevel::Public,
                ),
                'private_note' => new FieldDefinition(
                    name: 'private_note',
                    type: 'string',
                    targetEntityTypeId: 'template_cache_entity',
                    read: FieldReadLevel::Protected,
                ),
            ]);
        }

        $first = EntityReadRuntime::layoutFor(
            RuntimeTemplateCacheEntity::class,
            ['id' => 1, 'name' => 'First', 'private_note' => 'restricted'],
            'template_cache_entity',
            ['id' => 'id'],
            $firstRegistry,
            true,
        );
        self::assertSame($before + 1, $this->immutableSemanticTemplateCount());
        self::assertSame(1, $this->resolvedLayoutCacheCountFor($firstRegistry));

        $second = EntityReadRuntime::layoutFor(
            RuntimeTemplateCacheEntity::class,
            ['id' => 2, 'name' => 'Second', 'private_note' => 'restricted'],
            'template_cache_entity',
            ['id' => 'id'],
            $secondRegistry,
            true,
        );
        self::assertSame($before + 1, $this->immutableSemanticTemplateCount());
        self::assertSame(0, $this->resolvedLayoutCacheCountFor($secondRegistry));
        self::assertNotSame($first, $second);
        self::assertSame($first->fingerprint(), $second->fingerprint());

        $firstRegistry->registerCoreFields('template_cache_entity', [
            'private_note' => new FieldDefinition(
                name: 'private_note',
                type: 'string',
                targetEntityTypeId: 'template_cache_entity',
                read: FieldReadLevel::Internal,
            ),
        ]);
        $second->assertCurrent();
        try {
            $first->assertCurrent();
            self::fail('A cross-registry template detached the first layout from its registry generation.');
        } catch (StaleEntityReadLayout) {
            self::assertTrue(true);
        }

        $changed = EntityReadRuntime::layoutFor(
            RuntimeTemplateCacheEntity::class,
            ['id' => 1, 'name' => 'First', 'private_note' => 'internal'],
            'template_cache_entity',
            ['id' => 'id'],
            $firstRegistry,
            true,
        );
        self::assertSame(FieldReadLevel::Internal, $changed->level('private_note'));
        self::assertSame($before + 2, $this->immutableSemanticTemplateCount());
    }

    #[Test]
    public function custom_field_definitions_never_enter_the_cross_registry_template_cache(): void
    {
        $before = $this->immutableSemanticTemplateCount();
        foreach ([1, 2] as $id) {
            $definition = $this->createStub(FieldDefinitionInterface::class);
            $definition->method('getName')->willReturn('name');
            $definition->method('getTargetEntityTypeId')->willReturn('custom_template_cache_entity');
            $definition->method('getTargetBundle')->willReturn(null);
            $definition->method('getSetting')->willReturnCallback(
                static fn(string $setting): mixed => $setting === 'internal' ? false : null,
            );
            $registry = new FieldDefinitionRegistry();
            $registry->registerCoreFields('custom_template_cache_entity', ['name' => $definition]);

            $layout = EntityReadRuntime::layoutFor(
                RuntimeCustomTemplateCacheEntity::class,
                ['id' => $id, 'name' => 'Custom'],
                'custom_template_cache_entity',
                ['id' => 'id'],
                $registry,
                true,
            );
            self::assertSame(FieldReadLevel::Internal, $layout->level('name'));
        }

        self::assertSame($before, $this->immutableSemanticTemplateCount());
    }

    #[Test]
    public function registry_mutation_invalidates_an_already_compiled_layout(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('cache_entity', 'business', [
            new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                targetBundle: 'business',
                read: FieldReadLevel::Public,
            ),
        ]);
        $layout = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'bundle' => 'business', 'name' => 'Before'],
            'cache_entity',
            ['id' => 'id', 'bundle' => 'bundle'],
            $registry,
            true,
        );

        $registry->registerBundleFields('cache_entity', 'business', [
            new FieldDefinition(
                name: 'private_note',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                targetBundle: 'business',
                read: FieldReadLevel::Protected,
            ),
        ]);

        $replacement = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'bundle' => 'business', 'name' => 'After', 'private_note' => 'restricted'],
            'cache_entity',
            ['id' => 'id', 'bundle' => 'bundle'],
            $registry,
            true,
        );
        self::assertSame(FieldReadLevel::Protected, $replacement->level('private_note'));
        $this->expectException(StaleEntityReadLayout::class);
        $layout->assertCurrent();
    }

    #[Test]
    public function concrete_registry_mutation_immediately_stales_only_layouts_from_that_registry(): void
    {
        $changedRegistry = new FieldDefinitionRegistry();
        $unrelatedRegistry = new FieldDefinitionRegistry();
        foreach ([$changedRegistry, $unrelatedRegistry] as $registry) {
            $registry->registerCoreFields('cache_entity', [
                'name' => new FieldDefinition(
                    name: 'name',
                    type: 'string',
                    targetEntityTypeId: 'cache_entity',
                    read: FieldReadLevel::Public,
                ),
            ]);
        }
        $changed = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'name' => 'Changed'],
            'cache_entity',
            ['id' => 'id'],
            $changedRegistry,
            true,
        );
        $unrelated = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 2, 'name' => 'Unrelated'],
            'cache_entity',
            ['id' => 'id'],
            $unrelatedRegistry,
            true,
        );

        $changedRegistry->registerCoreFields('cache_entity', [
            'name' => new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                read: FieldReadLevel::Protected,
            ),
        ]);

        $unrelated->assertCurrent();
        try {
            $changed->assertCurrent();
            self::fail('A layout compiled from the mutated registry remained current.');
        } catch (StaleEntityReadLayout) {
            self::assertTrue(true);
        }
        $unrelated->assertCurrent();
    }

    #[Test]
    public function registry_generation_change_blocks_an_actual_read_from_the_previously_cached_layout(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('cache_entity', [
            'name' => new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                read: FieldReadLevel::Public,
            ),
        ]);
        $layout = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'name' => 'Previously public'],
            'cache_entity',
            ['id' => 'id'],
            $registry,
            true,
        );
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: ['id' => 1, 'name' => 'Previously public'],
            layout: $layout,
            structure: new EntityStructure('cache_entity', 'cache_entity', 1, fieldNames: ['id', 'name']),
            entityTypeId: 'cache_entity',
            entityKeys: ['id' => 'id'],
        );
        $entity = $boundary->installer()->instantiate(RuntimeRegistryCacheEntity::class, $payload);
        self::assertSame('Previously public', $entity->get('name'));

        $registry->registerCoreFields('cache_entity', [
            'name' => new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                read: FieldReadLevel::Protected,
            ),
        ]);

        $this->expectException(StaleEntityReadLayout::class);
        $entity->get('name');
    }

    #[Test]
    public function in_place_classification_tightening_advances_the_registry_generation_before_a_stale_read(): void
    {
        $level = FieldReadLevel::Public;
        $definition = $this->createMockForIntersectionOfInterfaces([
            FieldDefinitionInterface::class,
            FieldReadDefinitionInterface::class,
        ]);
        $definition->method('getName')->willReturn('name');
        $definition->method('getTargetEntityTypeId')->willReturn('cache_entity');
        $definition->method('getTargetBundle')->willReturn(null);
        $definition->method('getReadLevel')->willReturnCallback(static function () use (&$level): FieldReadLevel {
            return $level;
        });
        $definition->method('getSetting')->willReturn(null);

        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('cache_entity', ['name' => $definition]);
        $generation = $registry->fieldReadLayoutGeneration('cache_entity', 'cache_entity');
        $layout = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'name' => 'Previously public'],
            'cache_entity',
            ['id' => 'id'],
            $registry,
            true,
        );
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: ['id' => 1, 'name' => 'Previously public'],
            layout: $layout,
            structure: new EntityStructure('cache_entity', 'cache_entity', 1, fieldNames: ['id', 'name']),
            entityTypeId: 'cache_entity',
            entityKeys: ['id' => 'id'],
        );
        $entity = $boundary->installer()->instantiate(RuntimeRegistryCacheEntity::class, $payload);
        self::assertSame('Previously public', $entity->get('name'));
        $publicGeneration = $generation->current();

        $level = FieldReadLevel::Protected;

        try {
            $entity->get('name');
            self::fail('An in-place classification tightening served a stale Public value.');
        } catch (StaleEntityReadLayout) {
            self::assertSame(
                $generation,
                $registry->fieldReadLayoutGeneration('cache_entity', 'cache_entity'),
                'The registry must advance its existing generation source, not hide drift behind a replacement.',
            );
            self::assertSame($publicGeneration + 1, $generation->current());
        }
    }

    #[Test]
    public function a_custom_definition_probe_does_not_make_a_sealed_layout_retain_its_registry(): void
    {
        $definition = $this->createStub(FieldDefinitionInterface::class);
        $definition->method('getName')->willReturn('name');
        $definition->method('getTargetEntityTypeId')->willReturn('cache_entity');
        $definition->method('getTargetBundle')->willReturn(null);
        $definition->method('getSetting')->willReturnCallback(
            static fn(string $setting): mixed => $setting === 'internal' ? false : null,
        );
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('cache_entity', ['name' => $definition]);
        $layout = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'name' => 'Custom'],
            'cache_entity',
            ['id' => 'id'],
            $registry,
            true,
        );
        $registryReference = \WeakReference::create($registry);

        unset($registry);
        gc_collect_cycles();

        self::assertNull($registryReference->get());
        $this->expectException(StaleEntityReadLayout::class);
        $layout->assertCurrent();
    }

    #[Test]
    public function final_readonly_framework_definitions_keep_the_unprobed_generation_fast_path(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('cache_entity', [
            'name' => new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                read: FieldReadLevel::Public,
            ),
        ]);

        $generation = $registry->fieldReadLayoutGeneration('cache_entity', 'cache_entity');
        $provider = (new \ReflectionObject($generation))->getProperty('semanticFingerprintProvider');

        self::assertNull($provider->getValue($generation));
    }

    #[Test]
    public function bundle_registry_mutation_stales_only_the_exact_bundle_source(): void
    {
        $registry = new FieldDefinitionRegistry();
        foreach (['business', 'private'] as $bundle) {
            $registry->registerBundleFields('cache_entity', $bundle, [
                new FieldDefinition(
                    name: 'name',
                    type: 'string',
                    targetEntityTypeId: 'cache_entity',
                    targetBundle: $bundle,
                    read: FieldReadLevel::Public,
                ),
            ]);
        }
        $business = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'bundle' => 'business', 'name' => 'Business'],
            'cache_entity',
            ['id' => 'id', 'bundle' => 'bundle'],
            $registry,
            true,
        );
        $private = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 2, 'bundle' => 'private', 'name' => 'Private'],
            'cache_entity',
            ['id' => 'id', 'bundle' => 'bundle'],
            $registry,
            true,
        );

        $registry->registerBundleFields('cache_entity', 'business', [
            new FieldDefinition(
                name: 'private_note',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                targetBundle: 'business',
                read: FieldReadLevel::Protected,
            ),
        ]);

        $private->assertCurrent();
        self::assertSame(FieldReadLevel::Public, $private->level('name'));
        $this->expectException(StaleEntityReadLayout::class);
        $business->assertCurrent();
    }

    #[Test]
    public function anonymous_registry_replacement_does_not_stale_an_unrelated_layout(): void
    {
        $unrelated = EntityReadRuntime::layoutFor(
            RuntimePublicCacheEntity::class,
            ['id' => 10, 'name' => 'Unrelated'],
            'cache_entity',
            ['id' => 'id'],
            null,
            true,
        );
        $registry = new class implements FieldDefinitionRegistryInterface {
            /** @var array<string, object> */
            public array $fields = [];

            public function registerCoreFields(string $entityTypeId, array $fields): void
            {
                $this->fields = $fields;
            }

            public function mergeCoreFields(string $entityTypeId, array $fields): void {}

            public function registerBundleFields(string $entityTypeId, string $bundle, array $fields): void {}

            public function coreFieldsFor(string $entityTypeId): array
            {
                return $this->fields;
            }

            public function bundleFieldsFor(string $entityTypeId, string $bundle): array
            {
                return [];
            }

            public function bundleNamesFor(string $entityTypeId): array
            {
                return [];
            }

            public function bundlesDefiningField(string $entityTypeId, string $fieldName): array
            {
                return [];
            }
        };
        $registry->registerCoreFields('cache_entity', [
            'name' => new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                read: FieldReadLevel::Public,
            ),
        ]);
        EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'name' => 'Before'],
            'cache_entity',
            ['id' => 'id'],
            $registry,
            true,
        );
        $registry->registerCoreFields('cache_entity', [
            'name' => new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                read: FieldReadLevel::Protected,
            ),
        ]);
        EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 2, 'name' => 'After'],
            'cache_entity',
            ['id' => 'id'],
            $registry,
            true,
        );

        $unrelated->assertCurrent();
        self::assertSame(FieldReadLevel::Public, $unrelated->level('name'));
    }

    #[Test]
    public function compiled_layouts_do_not_cross_bundle_or_class_boundaries(): void
    {
        $registry = new FieldDefinitionRegistry();
        foreach (['business' => FieldReadLevel::Public, 'private' => FieldReadLevel::Protected] as $bundle => $level) {
            $registry->registerBundleFields('cache_entity', $bundle, [
                new FieldDefinition(
                    name: 'name',
                    type: 'string',
                    targetEntityTypeId: 'cache_entity',
                    targetBundle: $bundle,
                    read: $level,
                ),
            ]);
        }

        $business = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'bundle' => 'business', 'name' => 'Visible'],
            'cache_entity',
            ['id' => 'id', 'bundle' => 'bundle'],
            $registry,
            true,
        );
        $private = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 2, 'bundle' => 'private', 'name' => 'Restricted'],
            'cache_entity',
            ['id' => 'id', 'bundle' => 'bundle'],
            $registry,
            true,
        );
        $internalClass = EntityReadRuntime::layoutFor(
            RuntimeInternalCacheEntity::class,
            ['id' => 3, 'bundle' => 'internal', 'name' => 'Internal'],
            'cache_entity',
            ['id' => 'id', 'bundle' => 'bundle'],
            null,
            true,
        );

        self::assertSame(FieldReadLevel::Public, $business->level('name'));
        self::assertSame(FieldReadLevel::Protected, $private->level('name'));
        self::assertSame(FieldReadLevel::Internal, $internalClass->level('name'));
        self::assertNotSame($business, $private);
        self::assertNotSame($business, $internalClass);
    }

    #[Test]
    public function replacement_in_an_anonymous_registry_cannot_reuse_an_identity_cached_layout(): void
    {
        $registry = new class implements FieldDefinitionRegistryInterface {
            /** @var array<string, array<string, object>> */
            private array $core = [];

            public function registerCoreFields(string $entityTypeId, array $fields): void
            {
                $this->core[$entityTypeId] = $fields;
            }

            public function mergeCoreFields(string $entityTypeId, array $fields): void
            {
                $this->core[$entityTypeId] = ($this->core[$entityTypeId] ?? []) + $fields;
            }

            public function registerBundleFields(string $entityTypeId, string $bundle, array $fields): void {}

            public function coreFieldsFor(string $entityTypeId): array
            {
                return $this->core[$entityTypeId] ?? [];
            }

            public function bundleFieldsFor(string $entityTypeId, string $bundle): array
            {
                return [];
            }

            public function bundleNamesFor(string $entityTypeId): array
            {
                return [];
            }

            public function bundlesDefiningField(string $entityTypeId, string $fieldName): array
            {
                return [];
            }
        };
        $registry->registerCoreFields('cache_entity', [
            'name' => new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                read: FieldReadLevel::Public,
            ),
        ]);
        $public = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'name' => 'Before'],
            'cache_entity',
            ['id' => 'id'],
            $registry,
            true,
        );

        $registry->registerCoreFields('cache_entity', [
            'name' => new FieldDefinition(
                name: 'name',
                type: 'string',
                targetEntityTypeId: 'cache_entity',
                read: FieldReadLevel::Protected,
            ),
        ]);
        $protected = EntityReadRuntime::layoutFor(
            RuntimeRegistryCacheEntity::class,
            ['id' => 1, 'name' => 'After'],
            'cache_entity',
            ['id' => 'id'],
            $registry,
            true,
        );

        self::assertSame(FieldReadLevel::Public, $public->level('name'));
        self::assertSame(FieldReadLevel::Protected, $protected->level('name'));
        $this->expectException(StaleEntityReadLayout::class);
        $public->assertCurrent();
    }

    private function immutableSemanticTemplateCount(): int
    {
        $properties = (new \ReflectionClass(EntityReadRuntime::class))->getStaticProperties();

        return count($properties['immutableSemanticTemplates']);
    }

    private function resolvedLayoutCacheCountFor(FieldDefinitionRegistryInterface $registry): int
    {
        $runtimeProperties = (new \ReflectionClass(EntityReadRuntime::class))->getStaticProperties();
        /** @var \WeakMap<FieldDefinitionRegistryInterface, object> $scopes */
        $scopes = $runtimeProperties['registryCacheScopes'];
        $scopeProperty = (new \ReflectionObject($scopes[$registry]))->getProperty('sources');
        /** @var array<string, object> $sources */
        $sources = $scopeProperty->getValue($scopes[$registry]);
        self::assertCount(1, $sources);
        $source = reset($sources);
        $layoutsProperty = (new \ReflectionObject($source))->getProperty('layouts');
        /** @var array<string, object> $layouts */
        $layouts = $layoutsProperty->getValue($source);

        return count($layouts);
    }
}

#[ContentEntityType(id: 'cache_entity')]
final class RuntimeRegistryCacheEntity extends ContentEntityBase {}

#[ContentEntityType(id: 'cache_entity')]
final class RuntimePublicCacheEntity extends ContentEntityBase
{
    #[Field(read: FieldReadLevel::Public)]
    public string $name = '';
}

#[ContentEntityType(id: 'cache_entity')]
final class RuntimeInternalCacheEntity extends ContentEntityBase
{
    #[Field(read: FieldReadLevel::Internal)]
    public string $name = '';
}

#[ContentEntityType(id: 'template_cache_entity')]
final class RuntimeTemplateCacheEntity extends ContentEntityBase {}

#[ContentEntityType(id: 'custom_template_cache_entity')]
final class RuntimeCustomTemplateCacheEntity extends ContentEntityBase {}
