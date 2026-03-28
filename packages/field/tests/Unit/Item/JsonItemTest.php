<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\JsonItem;

#[CoversClass(JsonItem::class)]
final class JsonItemTest extends TestCase
{
    #[Test]
    public function schemaReturnsTextColumn(): void
    {
        $schema = JsonItem::schema();

        $this->assertSame(['value' => ['type' => 'text']], $schema);
    }

    #[Test]
    public function defaultValueReturnsNull(): void
    {
        $this->assertNull(JsonItem::defaultValue());
    }

    #[Test]
    public function defaultSettingsReturnsEmptyArray(): void
    {
        $this->assertSame([], JsonItem::defaultSettings());
    }

    #[Test]
    public function jsonSchemaReturnsObjectType(): void
    {
        $schema = JsonItem::jsonSchema();

        $this->assertSame(['type' => 'object'], $schema);
    }

    #[Test]
    public function propertyDefinitionsReturnValueAsString(): void
    {
        $this->assertSame(['value' => 'string'], JsonItem::propertyDefinitions());
    }

    #[Test]
    public function mainPropertyNameReturnsValue(): void
    {
        $this->assertSame('value', JsonItem::mainPropertyName());
    }
}
