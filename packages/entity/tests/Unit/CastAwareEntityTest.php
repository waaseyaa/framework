<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Cast\Exception\CastException;
use Waaseyaa\Entity\Cast\ValueCaster;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleStringEnum;

/**
 * @covers \Waaseyaa\Entity\EntityBase
 * @covers \Waaseyaa\Entity\ContentEntityBase
 */
#[CoversClass(EntityBase::class)]
#[CoversClass(ContentEntityBase::class)]
final class CastAwareEntityTest extends TestCase
{
    #[Test]
    public function get_applies_cast_in_for_constructor_values(): void
    {
        $entity = new CastingTestEntity([
            'count' => '42',
            'payload' => '{"a":1}',
        ]);

        self::assertSame(42, $entity->get('count'));
        self::assertSame(['a' => 1], $entity->get('payload'));
    }

    #[Test]
    public function constructor_does_not_cast_internal_values(): void
    {
        $entity = new CastingTestEntity([
            'count' => '42',
            'payload' => '{"a":1}',
        ]);

        self::assertSame('42', $entity->toArray()['count']);
        self::assertSame('{"a":1}', $entity->toArray()['payload']);
    }

    #[Test]
    public function set_casts_out_to_storage_shape(): void
    {
        $entity = new CastingTestEntity();
        $entity->set('count', 7);
        $entity->set('payload', ['b' => 2]);

        self::assertSame(7, $entity->toArray()['count']);
        self::assertSame('{"b":2}', $entity->toArray()['payload']);
    }

    #[Test]
    public function get_round_trips_after_set(): void
    {
        $entity = new CastingTestEntity();
        $entity->set('count', 100);
        $entity->set('payload', ['x' => true]);

        self::assertSame(100, $entity->get('count'));
        self::assertSame(['x' => true], $entity->get('payload'));
    }

    #[Test]
    public function uncast_fields_pass_through(): void
    {
        $entity = new CastingTestEntity(['plain' => 'hello']);
        self::assertSame('hello', $entity->get('plain'));

        $entity->set('plain', 'bye');
        self::assertSame('bye', $entity->toArray()['plain']);
    }

    #[Test]
    public function get_returns_null_when_key_missing_and_cast_defined(): void
    {
        $entity = new CastingTestEntity();
        self::assertNull($entity->get('count'));
    }

    #[Test]
    public function backed_enum_cast_on_entity(): void
    {
        $entity = new EnumCastingTestEntity(['status' => 'a']);
        self::assertSame(SampleStringEnum::Alpha, $entity->get('status'));

        $entity->set('status', SampleStringEnum::Beta);
        self::assertSame('b', $entity->toArray()['status']);
    }

    #[Test]
    public function invalid_stored_value_throws_on_get(): void
    {
        $entity = new CastingTestEntity(['count' => 'not-int']);
        $this->expectException(CastException::class);
        $entity->get('count');
    }

    #[Test]
    public function content_entity_inherits_cast_aware_get_set(): void
    {
        $entity = new CastingContentEntity(['tags' => '["x","y"]']);

        self::assertSame(['x', 'y'], $entity->get('tags'));
        self::assertSame('["x","y"]', $entity->toArray()['tags']);

        $entity->set('tags', ['z']);
        self::assertSame(['z'], $entity->get('tags'));
    }

    #[Test]
    public function value_caster_override_is_used(): void
    {
        $custom = new ValueCaster();
        $entity = new EntityWithInjectedCaster($custom, ['n' => '3']);

        self::assertSame(3, $entity->get('n'));
        self::assertSame($custom, $entity->injectedCaster);
    }
}

final class CastingTestEntity extends TestEntity
{
    /**
     * @var array<string, string|array<string, mixed>>
     */
    protected array $casts = [
        'count' => 'int',
        'payload' => 'array',
    ];
}

final class EnumCastingTestEntity extends TestEntity
{
    /**
     * @var array<string, string|array<string, mixed>>
     */
    protected array $casts = [
        'status' => SampleStringEnum::class,
    ];
}

final class CastingContentEntity extends TestContentEntity
{
    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public mixed $tags = null;

    /**
     * @var array<string, string|array<string, mixed>>
     */
    protected array $casts = [
        'tags' => 'array',
    ];
}

final class EntityWithInjectedCaster extends TestEntity
{
    /**
     * @var array<string, string|array<string, mixed>>
     */
    protected array $casts = ['n' => 'int'];

    public function __construct(
        public readonly ValueCaster $injectedCaster,
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys);
    }

    protected function valueCaster(): ValueCaster
    {
        return $this->injectedCaster;
    }
}
