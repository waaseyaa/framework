<?php

declare(strict_types=1);

namespace Fixture\CustomField;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\Field\FieldValueKindProviderInterface;

#[FieldType(id: 'fixture_custom_money', label: 'Fixture custom money')]
final class CustomMoneyFieldType extends AbstractFieldType implements FieldValueKindProviderInterface
{
    public static function schema(): array
    {
        return ['value' => ['type' => 'varchar', 'length' => 32]];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'pattern' => '^-?[0-9]+\\.[0-9]{2}$'];
    }

    public static function valueKind(): FieldValueKind
    {
        return FieldValueKind::String;
    }
}
