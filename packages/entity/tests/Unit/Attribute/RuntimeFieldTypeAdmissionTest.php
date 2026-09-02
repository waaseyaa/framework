<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldTypeManager;

#[CoversClass(EntityMetadataReader::class)]
#[CoversClass(FieldTypeManager::class)]
#[CoversClass(FieldDefinitionRegistry::class)]
final class RuntimeFieldTypeAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        EntityMetadataReader::clearCache();
        EntityType::clearFromClassCache();
    }

    protected function tearDown(): void
    {
        EntityMetadataReader::clearCache();
        EntityType::clearFromClassCache();
    }

    #[Test]
    public function attributed_entity_with_manifest_field_type_is_admitted_by_the_registry(): void
    {
        $entityType = EntityType::fromClass(RuntimeCustomFieldEntity::class);
        $manager = FieldTypeManager::fromManifest([
            'runtime_money' => RuntimeMoneyFieldType::class,
        ]);
        $registry = new FieldDefinitionRegistry($manager);

        $registry->registerCoreFields($entityType->id(), $entityType->getFieldDefinitions());

        self::assertSame('runtime_money', $entityType->getFieldDefinitions()['price']->getType());
        self::assertSame(
            ['type' => 'string', 'maxLength' => 32],
            $manager->entityValueJsonSchemaFor($entityType->getFieldDefinitions()['price']),
        );
        self::assertSame(['type' => 'varchar', 'length' => 32], $manager->entityStorageColumnSchemaFor($entityType->getFieldDefinitions()['price']));
    }

    #[Test]
    public function unknown_explicit_type_fails_at_registry_admission_with_entity_context(): void
    {
        $entityType = EntityType::fromClass(RuntimeUnknownFieldEntity::class);
        $registry = new FieldDefinitionRegistry(FieldTypeManager::default());

        try {
            $registry->registerCoreFields($entityType->id(), $entityType->getFieldDefinitions());
            self::fail('An unknown explicit field type must not enter the registry.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('runtime_missing', $exception->getMessage());
            self::assertStringContainsString('runtime_unknown_field', $exception->getMessage());
            self::assertStringContainsString('field-type registry', $exception->getMessage());
        }
    }
}

#[FieldType(id: 'runtime_money', label: 'Runtime money')]
final class RuntimeMoneyFieldType extends AbstractFieldType
{
    public static function schema(): array
    {
        return ['value' => ['type' => 'varchar', 'length' => 32]];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'maxLength' => 32];
    }
}

#[ContentEntityType(id: 'runtime_custom_field')]
final class RuntimeCustomFieldEntity extends ContentEntityBase
{
    #[Field(type: 'runtime_money')]
    public string $price = '';
}

#[ContentEntityType(id: 'runtime_unknown_field')]
final class RuntimeUnknownFieldEntity extends ContentEntityBase
{
    #[Field(type: 'runtime_missing')]
    public string $value = '';
}
