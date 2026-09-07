<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldScaffoldProjection;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\Field\FieldValueKindProviderInterface;

#[CoversClass(FieldScaffoldProjection::class)]
final class FieldScaffoldProjectionTest extends TestCase
{
    #[Test]
    public function registeredMetadataProjectsCompatiblePhpPropertiesWithoutATypeIdMap(): void
    {
        self::assertTrue(class_exists(FieldScaffoldProjection::class), 'The canonical scaffold projection must exist.');
        $projection = new FieldScaffoldProjection(new FieldTypeManager());

        self::assertSame(
            ['phpType' => 'string', 'defaultLiteral' => "''"],
            $projection->property(new FieldDefinition(name: 'body', type: 'text')),
        );
        self::assertSame(
            ['phpType' => 'mixed', 'defaultLiteral' => 'null'],
            $projection->property(new FieldDefinition(name: 'published_at', type: 'datetime')),
        );
        self::assertSame(
            ['phpType' => 'array', 'defaultLiteral' => '[]'],
            $projection->property(new FieldDefinition(name: 'tags', type: 'string', cardinality: -1)),
        );
        self::assertSame(
            ['phpType' => 'bool', 'defaultLiteral' => 'true'],
            $projection->property(new FieldDefinition(name: 'status', type: 'boolean', defaultValue: true)),
        );
    }

    #[Test]
    public function admissionFollowsTheRegisteredPluginRosterAndCompleteDefaultMetadata(): void
    {
        self::assertTrue(class_exists(FieldScaffoldProjection::class), 'The canonical scaffold projection must exist.');
        $projection = new FieldScaffoldProjection(new FieldTypeManager(
            extensionClasses: [
                'scaffold_markdown' => ScaffoldMarkdownFieldType::class,
                'scaffold_configured' => ScaffoldConfiguredFieldType::class,
            ],
        ));

        self::assertContains('scaffold_markdown', $projection->fieldTypeIds());
        self::assertNotContains('scaffold_configured', $projection->fieldTypeIds());
        self::assertContains('entity_reference', $projection->fieldTypeIds());
        self::assertNotContains('enum', $projection->fieldTypeIds());
    }

    #[Test]
    public function referenceDefinitionPreservesTheAuthoredTargetAndProjectsItsStorageKind(): void
    {
        $projection = new FieldScaffoldProjection(new FieldTypeManager());
        $definition = $projection->definition('author', 'entity_reference', 'user');

        self::assertSame('author', $definition->getName());
        self::assertSame('entity_reference', $definition->getType());
        self::assertSame('user', $definition->getSetting('target_entity_type_id'));
        self::assertSame(FieldValueKind::EntityReference, $projection->valueKind($definition->getType()));
        self::assertSame(['phpType' => '?int', 'defaultLiteral' => 'null'], $projection->property($definition));
    }

    #[Test]
    public function referenceDefinitionWithoutATargetIsRefusedBeforeGeneratingAProperty(): void
    {
        $projection = new FieldScaffoldProjection(new FieldTypeManager());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a target entity type');
        $projection->definition('author', 'entity_reference');
    }

    #[Test]
    public function registeredTypeRequiringMissingMetadataCannotProduceADefinition(): void
    {
        $projection = new FieldScaffoldProjection(new FieldTypeManager(
            extensionClasses: ['scaffold_configured' => ScaffoldConfiguredFieldType::class],
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be scaffolded without additional metadata');
        $projection->definition('configuration', 'scaffold_configured');
    }

    #[Test]
    public function unknownTypesFailClosedThroughTheRegisteredAuthority(): void
    {
        self::assertTrue(class_exists(FieldScaffoldProjection::class), 'The canonical scaffold projection must exist.');
        $projection = new FieldScaffoldProjection(new FieldTypeManager());

        $this->expectException(UnknownFieldTypeException::class);
        $projection->property(new FieldDefinition(name: 'mystery', type: 'not_registered'));
    }
}

#[FieldType(id: 'scaffold_markdown', label: 'Scaffold markdown')]
final class ScaffoldMarkdownFieldType extends AbstractFieldType implements FieldValueKindProviderInterface
{
    public static function valueKind(): FieldValueKind
    {
        return FieldValueKind::FormattedText;
    }

    public static function schema(): array
    {
        return ['value' => ['type' => 'text']];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }

    public static function entityValueJsonSchemaFor(\Waaseyaa\Field\FieldDefinitionInterface $def): array
    {
        return ['type' => 'string'];
    }
}

#[FieldType(id: 'scaffold_configured', label: 'Configured scaffold field')]
final class ScaffoldConfiguredFieldType extends AbstractFieldType implements FieldValueKindProviderInterface
{
    public static function valueKind(): FieldValueKind
    {
        return FieldValueKind::String;
    }

    public static function defaultSettings(): array
    {
        return ['required_profile' => null];
    }

    public static function schema(): array
    {
        return ['value' => ['type' => 'varchar', 'length' => 255]];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }
}
