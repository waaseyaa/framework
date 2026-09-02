<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Field\Item\BooleanItem;
use Waaseyaa\Field\Item\EntityReferenceItem;
use Waaseyaa\Field\Item\FloatItem;
use Waaseyaa\Field\Item\IntegerItem;
use Waaseyaa\Field\Item\StringItem;
use Waaseyaa\Field\Item\TextItem;
use Waaseyaa\Plugin\PluginManagerInterface;

/**
 * @covers \Waaseyaa\Field\FieldTypeManager
 */
#[CoversClass(FieldTypeManager::class)]
final class FieldTypeManagerTest extends TestCase
{
    private FieldTypeManager $manager;

    protected function setUp(): void
    {
        $this->manager = new FieldTypeManager(
            directories: [
                dirname(__DIR__, 2) . '/src/Item',
            ],
        );
    }

    public function testImplementsInterfaces(): void
    {
        $this->assertInstanceOf(FieldTypeManagerInterface::class, $this->manager);
        $this->assertInstanceOf(PluginManagerInterface::class, $this->manager);
    }

    public function testDiscoversCoreFieldTypes(): void
    {
        $definitions = $this->manager->getDefinitions();

        $this->assertArrayHasKey('string', $definitions);
        $this->assertArrayHasKey('integer', $definitions);
        $this->assertArrayHasKey('boolean', $definitions);
        $this->assertArrayHasKey('float', $definitions);
        $this->assertArrayHasKey('text', $definitions);
        $this->assertArrayHasKey('entity_reference', $definitions);
    }

    #[Test]
    public function explicitEmptyDiscoveryDirectoriesRemainEmpty(): void
    {
        self::assertSame([], (new FieldTypeManager(directories: []))->getDefinitions());
    }

    public function testGetDefinition(): void
    {
        $definition = $this->manager->getDefinition('string');

        $this->assertSame('string', $definition->id);
        $this->assertSame('String', $definition->label);
        $this->assertSame(StringItem::class, $definition->class);
    }

    public function testHasDefinition(): void
    {
        $this->assertTrue($this->manager->hasDefinition('string'));
        $this->assertTrue($this->manager->hasDefinition('integer'));
        $this->assertFalse($this->manager->hasDefinition('nonexistent'));
    }

    public function testGetDefinitionThrowsForUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->getDefinition('nonexistent');
    }

    public function testGetDefaultSettings(): void
    {
        $settings = $this->manager->getDefaultSettings('string');

        $this->assertSame([], $settings);
    }

    public function testGetColumnsForString(): void
    {
        $columns = $this->manager->getColumns('string');

        $this->assertSame([
            'value' => ['type' => 'varchar', 'length' => 255],
        ], $columns);
    }

    public function testGetColumnsForInteger(): void
    {
        $columns = $this->manager->getColumns('integer');

        $this->assertSame([
            'value' => ['type' => 'int'],
        ], $columns);
    }

    public function testGetColumnsForBoolean(): void
    {
        $columns = $this->manager->getColumns('boolean');

        $this->assertSame([
            'value' => ['type' => 'int', 'size' => 'tiny'],
        ], $columns);
    }

    public function testGetColumnsForFloat(): void
    {
        $columns = $this->manager->getColumns('float');

        $this->assertSame([
            'value' => ['type' => 'float'],
        ], $columns);
    }

    public function testGetColumnsForText(): void
    {
        $columns = $this->manager->getColumns('text');

        $this->assertSame([
            'value' => ['type' => 'text'],
            'format' => ['type' => 'varchar', 'length' => 255],
        ], $columns);
    }

    public function testGetColumnsForEntityReference(): void
    {
        $columns = $this->manager->getColumns('entity_reference');

        $this->assertSame([
            'target_id' => ['type' => 'int'],
            'target_type' => ['type' => 'varchar', 'length' => 255],
        ], $columns);
    }

    public function testCreateInstance(): void
    {
        $instance = $this->manager->createInstance('string');

        $this->assertInstanceOf(StringItem::class, $instance);
        $this->assertSame('string', $instance->getPluginId());
    }

    public function testCreateInstanceForAllTypes(): void
    {
        $this->assertInstanceOf(StringItem::class, $this->manager->createInstance('string'));
        $this->assertInstanceOf(IntegerItem::class, $this->manager->createInstance('integer'));
        $this->assertInstanceOf(BooleanItem::class, $this->manager->createInstance('boolean'));
        $this->assertInstanceOf(FloatItem::class, $this->manager->createInstance('float'));
        $this->assertInstanceOf(TextItem::class, $this->manager->createInstance('text'));
        $this->assertInstanceOf(EntityReferenceItem::class, $this->manager->createInstance('entity_reference'));
    }

    public function testFieldTypeDefinitionClasses(): void
    {
        $definitions = $this->manager->getDefinitions();

        $expectedClasses = [
            'string' => StringItem::class,
            'integer' => IntegerItem::class,
            'boolean' => BooleanItem::class,
            'float' => FloatItem::class,
            'text' => TextItem::class,
            'entity_reference' => EntityReferenceItem::class,
        ];

        foreach ($expectedClasses as $id => $class) {
            $this->assertSame($class, $definitions[$id]->class, "Class mismatch for '$id'");
        }
    }

    #[Test]
    public function defaultDiscoveryIncludesTheLiveLongTextPlugin(): void
    {
        $manager = new FieldTypeManager();

        self::assertTrue($manager->hasDefinition('text_long'));
        self::assertSame(['type' => 'string'], $manager->entityValueJsonSchemaFor(
            new FieldDefinition(name: 'body', type: 'text_long'),
        ));
    }

    #[Test]
    public function classificationLabelSeparatesScalarEntityValueFromMultiColumnStorage(): void
    {
        $manager = new FieldTypeManager();
        $definition = new FieldDefinition(name: 'classification_label', type: 'classification_label');

        self::assertSame(
            ['type' => ['string', 'null']],
            $manager->entityValueJsonSchemaFor($definition),
        );
        self::assertSame(
            [
                'type' => 'varchar',
                'description' => 'Classification label_id reference; indexed',
                'index' => true,
            ],
            $manager->entityStorageColumnSchemaFor($definition),
        );
    }

    #[Test]
    public function fieldItemSchemaFailsClosedForUnknownTypes(): void
    {
        $this->expectException(UnknownFieldTypeException::class);
        $this->manager->jsonSchemaFor(new FieldDefinition(name: 'mystery', type: 'not_registered'));
    }

    #[Test]
    public function entityValueSchemaFailsClosedForUnknownTypes(): void
    {
        $this->expectException(UnknownFieldTypeException::class);
        $this->manager->entityValueJsonSchemaFor(new FieldDefinition(name: 'mystery', type: 'not_registered'));
    }

    #[Test]
    public function storageSchemaFailsClosedForUnknownTypes(): void
    {
        $this->expectException(UnknownFieldTypeException::class);
        $this->manager->schemaFor(new FieldDefinition(name: 'mystery', type: 'not_registered'));
    }

    #[Test]
    public function blueprintAdmissionIsSortedAndExcludesRelationshipMediaAndLongText(): void
    {
        $ids = $this->manager->blueprintFieldTypeIds();
        $sorted = $ids;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $ids);
        self::assertContains('string', $ids);
        self::assertNotContains('entity_reference', $ids);
        self::assertNotContains('file', $ids);
        self::assertNotContains('image', $ids);
        self::assertNotContains('text_long', $ids);
    }
}
