<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Maps Waaseyaa field types to GraphQL scalar/object types.
 *
 * Mirrors the mapping in FieldDefinition::toJsonSchema().
 */
final class FieldTypeMapper
{
    private ?ObjectType $textType = null;
    private ?InputObjectType $textInputType = null;
    private ?InputObjectType $entityReferenceInputType = null;

    public function toOutputType(string $fieldType, bool $isMultiple): Type
    {
        $type = match ($fieldType) {
            'string', 'email', 'uri', 'timestamp', 'datetime', 'list_string' => Type::string(),
            'integer' => Type::int(),
            'boolean' => Type::boolean(),
            'float', 'decimal' => Type::float(),
            'text', 'text_long' => $this->getTextType(),
            default => Type::string(),
        };

        return $isMultiple ? Type::listOf(Type::nonNull($type)) : $type;
    }

    public function toInputType(string $fieldType, bool $isMultiple): Type
    {
        $type = match ($fieldType) {
            'string', 'email', 'uri', 'timestamp', 'datetime', 'list_string' => Type::string(),
            'integer' => Type::int(),
            'boolean' => Type::boolean(),
            'float', 'decimal' => Type::float(),
            'text', 'text_long' => $this->getTextInputType(),
            'entity_reference' => $this->getEntityReferenceInputType(),
            default => Type::string(),
        };

        return $isMultiple ? Type::listOf(Type::nonNull($type)) : $type;
    }

    public function isEntityReference(string $fieldType): bool
    {
        return $fieldType === 'entity_reference';
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
