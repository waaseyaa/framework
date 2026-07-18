<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\DateTime;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\DateTime\TimestampFieldConvention;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Tests\Unit\TestEntity;

/**
 * @covers \Waaseyaa\Entity\DateTime\TimestampFieldConvention
 */
final class TimestampFieldConventionTest extends TestCase
{
    #[Test]
    public function infer_auto_populate_from_names(): void
    {
        self::assertSame('create', TimestampFieldConvention::inferAutoPopulate('created', []));
        self::assertSame('create', TimestampFieldConvention::inferAutoPopulate('created_at', []));
        self::assertSame('update', TimestampFieldConvention::inferAutoPopulate('changed', []));
        self::assertSame('update', TimestampFieldConvention::inferAutoPopulate('updated_at', []));
        self::assertSame('update', TimestampFieldConvention::inferAutoPopulate('modified_at', []));
        self::assertNull(TimestampFieldConvention::inferAutoPopulate('last_message_at', []));
    }

    #[Test]
    public function explicit_auto_populate_overrides_name(): void
    {
        self::assertSame(
            'update',
            TimestampFieldConvention::inferAutoPopulate('created_at', ['auto_populate' => 'update']),
        );
        self::assertNull(
            TimestampFieldConvention::inferAutoPopulate('changed', ['auto_populate' => false]),
        );
    }

    #[Test]
    public function invalid_auto_populate_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimestampFieldConvention::inferAutoPopulate('x', ['auto_populate' => 'nope']);
    }

    #[Test]
    public function resolve_storage_format_defaults(): void
    {
        self::assertSame('unix', TimestampFieldConvention::resolveStorageFormat('created', []));
        self::assertSame('unix', TimestampFieldConvention::resolveStorageFormat('changed', []));
        self::assertSame('iso8601', TimestampFieldConvention::resolveStorageFormat('created_at', []));
        self::assertSame('iso8601', TimestampFieldConvention::resolveStorageFormat('updated_at', []));
        self::assertSame('unix', TimestampFieldConvention::resolveStorageFormat('stamp', []));
    }

    #[Test]
    public function explicit_storage_format_in_definition(): void
    {
        self::assertSame(
            'unix',
            TimestampFieldConvention::resolveStorageFormat('created_at', ['storage_format' => 'unix']),
        );
    }

    #[Test]
    public function invalid_storage_format_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimestampFieldConvention::resolveStorageFormat('x', ['storage_format' => 'nope']);
    }

    #[Test]
    public function is_raw_timestamp_unset_uses_to_array_not_get(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('toArray')->willReturn(['created' => 0]);
        self::assertTrue(TimestampFieldConvention::isRawTimestampUnset($entity, 'created'));

        $entity2 = $this->createStub(EntityInterface::class);
        $entity2->method('toArray')->willReturn(['created' => 1700000000]);
        self::assertFalse(TimestampFieldConvention::isRawTimestampUnset($entity2, 'created'));

        $entity3 = $this->createStub(EntityInterface::class);
        $entity3->method('toArray')->willReturn([]);
        self::assertTrue(TimestampFieldConvention::isRawTimestampUnset($entity3, 'created'));
    }

    #[Test]
    public function framework_timestamp_probe_does_not_export_unrelated_internal_fields(): void
    {
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: ['created' => 0, 'mail' => 'member@example.test'],
            layout: new EntityReadLayout(new EntityReadLayoutGeneration(), [
                'created' => FieldReadLevel::Public,
                'mail' => FieldReadLevel::Internal,
            ]),
            structure: new EntityStructure('article', 'article', null, null, fieldNames: ['created', 'mail']),
            entityTypeId: 'article',
            entityKeys: ['id' => 'id'],
        );
        $entity = $boundary->installer()->instantiate(TestEntity::class, $payload);

        self::assertTrue(TimestampFieldConvention::isRawTimestampUnset($entity, 'created'));
    }
}
