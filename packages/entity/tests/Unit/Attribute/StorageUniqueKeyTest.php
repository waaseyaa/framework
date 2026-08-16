<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\StorageUniqueKey;

#[CoversClass(StorageUniqueKey::class)]
final class StorageUniqueKeyTest extends TestCase
{
    #[Test]
    public function acceptsANameAndDistinctFields(): void
    {
        $key = new StorageUniqueKey('unique_slug_language', ['slug', 'langcode']);

        self::assertSame('unique_slug_language', $key->name);
        self::assertSame(['slug', 'langcode'], $key->fields);
    }

    #[Test]
    public function rejectsAnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageUniqueKey('', ['slug']);
    }

    #[Test]
    public function rejectsDuplicateFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageUniqueKey('unique_slug', ['slug', 'slug']);
    }
}
