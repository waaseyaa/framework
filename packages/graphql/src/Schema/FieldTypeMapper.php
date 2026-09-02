<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Schema;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\Field\FieldValueKindResolverInterface;

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
        $type = match ($this->valueKind($fieldType)) {
            FieldValueKind::String => Type::string(),
            FieldValueKind::Integer => Type::int(),
            FieldValueKind::Boolean => Type::boolean(),
            FieldValueKind::Float => Type::float(),
            FieldValueKind::FormattedText => $this->getTextType(),
            FieldValueKind::EntityReference => throw new \DomainException(sprintf(
                'GraphQL entity-reference output for registered field type "%s" requires target metadata.',
                $fieldType,
            )),
        };

        return $isMultiple ? Type::listOf(Type::nonNull($type)) : $type;
    }

    public function toInputType(string $fieldType, bool $isMultiple): Type
    {
        $type = match ($this->valueKind($fieldType)) {
            FieldValueKind::String => Type::string(),
            FieldValueKind::Integer => Type::int(),
            FieldValueKind::Boolean => Type::boolean(),
            FieldValueKind::Float => Type::float(),
            FieldValueKind::FormattedText => $this->getTextInputType(),
            FieldValueKind::EntityReference => $this->getEntityReferenceInputType(),
        };

        return $isMultiple ? Type::listOf(Type::nonNull($type)) : $type;
    }

    public function isEntityReference(string $fieldType): bool
    {
        return $this->valueKind($fieldType) === FieldValueKind::EntityReference;
    }

    private function valueKind(string $fieldType): FieldValueKind
    {
        if (!$this->fieldTypes instanceof FieldValueKindResolverInterface) {
            throw new \DomainException(sprintf(
                'The field-type registry cannot resolve a transport-neutral value kind for "%s".',
                $fieldType,
            ));
        }

        return $this->fieldTypes->valueKind($fieldType);
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
