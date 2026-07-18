<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleNestedChildVo;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleNestedParentVo;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleStringEnum;

#[CoversClass(EntityValues::class)]
final class EntityValuesTest extends TestCase
{
    #[Test]
    public function selected_projection_enumerates_without_exporting_unselected_internal_fields(): void
    {
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: ['id' => 7, 'title' => 'Public title', 'mail' => 'member@example.test'],
            layout: new EntityReadLayout(new EntityReadLayoutGeneration(), [
                'id' => FieldReadLevel::Public,
                'title' => FieldReadLevel::Public,
                'mail' => FieldReadLevel::Internal,
            ]),
            structure: new EntityStructure('article', 'article', 7, null, fieldNames: ['id', 'title', 'mail']),
            entityTypeId: 'article',
            entityKeys: ['id' => 'id', 'label' => 'title'],
        );
        $entity = $boundary->installer()->instantiate(TestEntity::class, $payload);

        self::assertSame(['id' => 7, 'title' => 'Public title'], EntityValues::toCastAwareMap($entity, ['id', 'title']));
        self::assertSame(['id', 'mail', 'title'], EntityValues::fieldNames($entity));
        self::assertSame(['id' => 7, 'title' => 'Public title'], EntityValues::toCastAwareMap($entity));
    }

    #[Test]
    public function toCastAwareMapUsesGetPerKey(): void
    {
        $entity = new class (['title' => 'hello', 'n' => '3']) extends ContentEntityBase {
            protected array $casts = ['n' => 'int'];

            public function __construct(array $values = [])
            {
                parent::__construct($values, 'article', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'bundle']);
            }
        };

        $map = EntityValues::toCastAwareMap($entity);

        self::assertSame('hello', $map['title']);
        self::assertSame(3, $map['n']);
        self::assertSame('hello', $entity->toArray()['title']);
        self::assertSame('3', $entity->toArray()['n']);
    }

    #[Test]
    public function statusToIntNormalizesBooleansAndStrings(): void
    {
        self::assertSame(1, EntityValues::statusToInt(true));
        self::assertSame(0, EntityValues::statusToInt(false));
        self::assertSame(1, EntityValues::statusToInt('published'));
        self::assertSame(0, EntityValues::statusToInt('0'));
        self::assertSame(1, EntityValues::statusToInt(1));
        self::assertSame(0, EntityValues::statusToInt(2));
    }

    #[Test]
    public function normalizeValueForJsonHandlesEnumsDatesAndArrays(): void
    {
        self::assertSame('a', EntityValues::normalizeValueForJson(SampleStringEnum::Alpha));
        self::assertSame(
            '2026-01-02T03:04:05+00:00',
            EntityValues::normalizeValueForJson(new \DateTimeImmutable('2026-01-02T03:04:05+00:00')),
        );
        self::assertSame(['x' => 'a'], EntityValues::normalizeValueForJson(['x' => SampleStringEnum::Alpha]));
    }

    #[Test]
    public function normalizeValueForJsonRecursesNestedValueObjectsLikeNestedArrays(): void
    {
        $nested = new SampleNestedParentVo(child: new SampleNestedChildVo(code: 'c1'));
        self::assertSame(
            ['child' => ['code' => 'c1']],
            EntityValues::normalizeValueForJson($nested),
        );
        self::assertSame(
            ['wrap' => ['child' => ['code' => 'c2']]],
            EntityValues::normalizeValueForJson([
                'wrap' => new SampleNestedParentVo(child: new SampleNestedChildVo(code: 'c2')),
            ]),
        );
    }

    #[Test]
    public function toJsonReadyMapAppliesCastsAndJsonNormalization(): void
    {
        $entity = new class (['title' => 't', 'at' => '2026-04-09T12:00:00+00:00']) extends ContentEntityBase {
            protected array $casts = ['at' => 'datetime_immutable'];

            public function __construct(array $values = [])
            {
                parent::__construct($values, 'article', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'bundle']);
            }
        };

        $map = EntityValues::toJsonReadyMap($entity);

        self::assertSame('t', $map['title']);
        self::assertSame('2026-04-09T12:00:00+00:00', $map['at']);
    }
}
