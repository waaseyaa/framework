<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Fixtures;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldValueKind;

#[FieldType(id: 'fixture_money', label: 'Fixture money')]
final class CustomMoneyFieldType extends AbstractFieldType implements \Waaseyaa\Field\FieldValueKindProviderInterface
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
