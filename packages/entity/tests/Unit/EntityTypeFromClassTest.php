<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Exception\EntityMetadataException;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\BenchmarkFixture;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\EmptyFieldsFixture;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\FactoryChildFixture;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\MissingAttributeFixture;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\NoLabelFixture;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\RevisionableFixture;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\SimpleFixture;

require_once __DIR__ . '/../Fixtures/AttributeFirstEntities/FactoryTestFixtures.php';

/**
 * @covers \Waaseyaa\Entity\EntityType::fromClass
 */
final class EntityTypeFromClassTest extends TestCase
{
    protected function setUp(): void
    {
        EntityType::clearFromClassCache();
        EntityMetadataReader::clearCache();
    }

    public function testHappyPathBuildsPopulatedInstance(): void
    {
        $type = EntityType::fromClass(SimpleFixture::class);

        self::assertSame('simple', $type->id());
        self::assertSame('Simple', $type->getLabel());
        self::assertSame(SimpleFixture::class, $type->getClass());
        self::assertSame('A simple test entity.', $type->getDescription());
        self::assertTrue($type->isApiExposed());

        $fields = $type->getFieldDefinitions();
        self::assertArrayHasKey('title', $fields);
        self::assertArrayHasKey('count', $fields);
        self::assertArrayHasKey('active', $fields);
    }

    public function testEmptyFieldMapEntity(): void
    {
        $type = EntityType::fromClass(EmptyFieldsFixture::class);

        self::assertSame('empty_fields', $type->id());
        self::assertSame([], $type->getFieldDefinitions());
        self::assertFalse($type->isApiExposed());
    }

    public function testTenancyOverrideIsForwardedOnFirstCall(): void
    {
        $type = EntityType::fromClass(
            SimpleFixture::class,
            tenancy: ['scope' => 'community'],
        );

        self::assertSame(['scope' => 'community'], $type->getTenancy());
    }

    public function testCacheReturnsSameInstanceWhenTenancyMatches(): void
    {
        $first = EntityType::fromClass(
            SimpleFixture::class,
            tenancy: ['scope' => 'community'],
        );
        $second = EntityType::fromClass(
            SimpleFixture::class,
            tenancy: ['scope' => 'community'],
        );

        self::assertSame($first, $second);
    }

    public function testCacheRejectsConflictingTenancyOverride(): void
    {
        // Mission #1257 §C1: tenancy is a security boundary, not a presentation
        // override. Silently returning the cached non-tenant instance would
        // disable community scoping for the second caller.
        EntityType::fromClass(SimpleFixture::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/tenancy/i');

        EntityType::fromClass(
            SimpleFixture::class,
            tenancy: ['scope' => 'community'],
        );
    }

    public function testCacheRejectsRemovingTenancyAfterDeclaring(): void
    {
        EntityType::fromClass(
            SimpleFixture::class,
            tenancy: ['scope' => 'community'],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/tenancy/i');

        EntityType::fromClass(SimpleFixture::class);
    }

    public function testLabelDefaultsToUcfirstOfTypeIdWhenAttributeOmitsLabel(): void
    {
        $type = EntityType::fromClass(NoLabelFixture::class);

        self::assertSame('No_label_fixture', $type->getLabel());
        self::assertNull($type->getDescription());
    }

    public function testOverrideParametersAreHonored(): void
    {
        // RevisionableFixture declares a revision key so revisionable: true passes T036.
        $type = EntityType::fromClass(
            RevisionableFixture::class,
            storageClass: 'Custom\\Storage',
            revisionable: true,
            translatable: true,
            bundleEntityType: 'simple_type',
            constraints: ['unique' => ['title']],
            group: 'content',
        );

        self::assertSame('Custom\\Storage', $type->getStorageClass());
        self::assertTrue($type->isRevisionable());
        self::assertTrue($type->isTranslatable());
        self::assertSame('simple_type', $type->getBundleEntityType());
        self::assertSame(['unique' => ['title']], $type->getConstraints());
        self::assertSame('content', $type->getGroup());
    }

    public function testCacheReturnsSameInstance(): void
    {
        $a = EntityType::fromClass(SimpleFixture::class);
        $b = EntityType::fromClass(SimpleFixture::class);

        self::assertSame($a, $b);
    }

    public function testInheritanceMergesFieldsAndChildOverrides(): void
    {
        $type = EntityType::fromClass(FactoryChildFixture::class);

        self::assertSame('factory_child', $type->id());
        $fields = $type->getFieldDefinitions();

        // Parent field inherited:
        self::assertArrayHasKey('weight', $fields);

        // Child override of `name`:
        self::assertArrayHasKey('name', $fields);
        self::assertSame('Child name', $fields['name']->getLabel());

        // Child-only field:
        self::assertArrayHasKey('extra', $fields);
    }

    public function testMissingContentEntityTypeAttributeThrows(): void
    {
        $this->expectException(EntityMetadataException::class);
        $this->expectExceptionMessage('must declare #[ContentEntityType]');

        EntityType::fromClass(MissingAttributeFixture::class);
    }

    public function testBenchmarkFixtureBuildsWithAllFields(): void
    {
        $type = EntityType::fromClass(BenchmarkFixture::class);
        self::assertGreaterThanOrEqual(12, \count($type->getFieldDefinitions()));
    }

    public function testFromClassCacheIsClassKeyedNotOverrideKeyed(): void
    {
        // Per spec risk note: cache key is class name only. Same class →
        // same instance regardless of override params on the second call.
        $first = EntityType::fromClass(SimpleFixture::class, group: 'one');
        $second = EntityType::fromClass(SimpleFixture::class, group: 'two');

        self::assertSame($first, $second);
        self::assertSame('one', $first->getGroup());
    }

    public function testPassingFieldDefinitionsAsNamedArgumentToConstructorThrows(): void
    {
        $this->expectException(\Error::class);

        // PHP 8.4 throws \Error: Unknown named parameter $fieldDefinitions.
        // @phpstan-ignore-next-line — intentional API-break verification.
        new EntityType(
            id: 'foo',
            label: 'Foo',
            class: ContentEntityBase::class,
            fieldDefinitions: [],
        );
    }
}
