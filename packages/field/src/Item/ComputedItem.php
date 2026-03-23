<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\ComputedFieldInterface;
use Waaseyaa\Field\FieldItemBase;

#[FieldType(
    id: 'computed',
    label: 'Computed',
    description: 'A virtual field whose value is computed at runtime. Not stored in the database.',
    category: 'general',
    defaultCardinality: 1,
)]
class ComputedItem extends FieldItemBase implements ComputedFieldInterface
{
    public static function propertyDefinitions(): array
    {
        return [
            'value' => 'string',
        ];
    }

    public static function mainPropertyName(): string
    {
        return 'value';
    }

    public static function schema(): array
    {
        return [];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }

    public function compute(EntityInterface $entity): mixed
    {
        return $this->getValue();
    }
}
