<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;

/**
 * Maps Waaseyaa field types to GraphQL scalar/object types.
 *
 * This is a GraphQL wire-type adapter, not JSON Schema authority. It accepts
 * only ids present in the field-type registry and fails closed otherwise.
 */
final class FieldTypeMapper
{
    private ?ObjectType $textType = null;
    private ?InputObjectType $textInputType = null;
    private ?InputObjectType $entityReferenceInputType = null;

    public function __construct(?FieldTypeManagerInterface $fieldTypes = null)
    {
        // Isolated construction: a mapper built without a registry (unit tests)
        // adapts the built-in roster; SchemaFactory threads the boot-scoped
        // registry it receives from GraphQlEndpoint / GraphQlRouter.
        $this->fieldTypes = $fieldTypes ?? FieldTypeManager::default();
    }

    private readonly FieldTypeManagerInterface $fieldTypes;

    public function toOutputType(string $fieldType, bool $isMultiple): Type
    {
        $this->assertRegistered($fieldType);
        $type = match ($fieldType) {
            'string', 'email', 'date', 'datetime', 'list', 'enum', 'json', 'link', 'file', 'image', 'text_long', 'decimal', 'classification_label' => Type::string(),
            'integer' => Type::int(),
            'boolean' => Type::boolean(),
            'float' => Type::float(),
            'text' => $this->getTextType(),
            default => throw new \DomainException(sprintf('GraphQL has no output adapter for registered field type "%s".', $fieldType)),
        };

        return $isMultiple ? Type::listOf(Type::nonNull($type)) : $type;
    }

    public function toInputType(string $fieldType, bool $isMultiple): Type
    {
        $this->assertRegistered($fieldType);
        $type = match ($fieldType) {
            'string', 'email', 'date', 'datetime', 'list', 'enum', 'json', 'link', 'file', 'image', 'text_long', 'decimal', 'classification_label' => Type::string(),
            'integer' => Type::int(),
            'boolean' => Type::boolean(),
            'float' => Type::float(),
            'text' => $this->getTextInputType(),
            'entity_reference' => $this->getEntityReferenceInputType(),
            default => throw new \DomainException(sprintf('GraphQL has no input adapter for registered field type "%s".', $fieldType)),
        };

        return $isMultiple ? Type::listOf(Type::nonNull($type)) : $type;
    }

    public function isEntityReference(string $fieldType): bool
    {
        return $fieldType === 'entity_reference';
    }

    private function assertRegistered(string $fieldType): void
    {
        if (!$this->fieldTypes->hasDefinition($fieldType)) {
            throw UnknownFieldTypeException::for($fieldType);
        }
    }

    private function getTextType(): ObjectType
    {
        return $this->textType ??= new ObjectType([
            'name' => 'TextValue',
            'fields' => [
                'value' => Type::string(),
                'format' => Type::string(),
            ],
        ]);
    }

    private function getTextInputType(): InputObjectType
    {
        return $this->textInputType ??= new InputObjectType([
            'name' => 'TextValueInput',
            'fields' => [
                'value' => Type::string(),
                'format' => Type::string(),
            ],
        ]);
    }

    private function getEntityReferenceInputType(): InputObjectType
    {
        return $this->entityReferenceInputType ??= new InputObjectType([
            'name' => 'EntityReferenceInput',
            'fields' => [
                'target_id' => Type::nonNull(Type::id()),
                'target_type' => Type::string(),
            ],
        ]);
    }
}
