<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Fixtures;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(id: 'fixture_undeclared_kind', label: 'Fixture undeclared kind')]
final class UndeclaredValueKindFieldType extends AbstractFieldType
{
    public static function schema(): array
    {
        return ['value' => ['type' => 'varchar', 'length' => 32]];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }
}
