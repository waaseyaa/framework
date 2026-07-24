<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\ApiSerializeTestEnum;
use Waaseyaa\Api\Tests\Fixtures\CastAwareSerializeTestEntity;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

#[CoversClass(ResourceSerializer::class)]
final class ResourceSerializerCastAwareTest extends TestCase
{
    #[Test]
    public function serializeUsesGetSoBackedEnumCastExposesBackingValueInAttributes(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(TestEntityType::stub(
            'cast_article',
            keys: TestEntity::definitionKeys(),
            class: CastAwareSerializeTestEntity::class,
            label: 'Cast article',
        ));

        $serializer = new ResourceSerializer($manager);
        $entity = new CastAwareSerializeTestEntity([
            'id' => 1,
            'uuid' => 'uuid-cast-enum',
            'title' => 'T',
            'type' => 'blog',
            'phase' => ApiSerializeTestEnum::Alpha->value,
        ], 'cast_article');

        $resource = $serializer->serialize($entity);

        $this->assertSame(ApiSerializeTestEnum::Alpha->value, $resource->attributes['phase']);
        $json = json_encode($resource->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"phase":"alpha"', $json);
    }

    #[Test]
    public function serializeFormatsDatetimeImmutableFromCastWhenFieldDefIsTimestamp(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(TestEntityType::stub(
            'cast_article',
            ['published_at' => new FieldDefinition(name: 'published_at', type: 'timestamp')],
            keys: TestEntity::definitionKeys(),
            class: CastAwareSerializeTestEntity::class,
            label: 'Cast article',
        ));

        $serializer = new ResourceSerializer($manager);
        $unix = 1_709_510_400;
        $entity = new CastAwareSerializeTestEntity([
            'id' => 1,
            'uuid' => 'uuid-cast-dt',
            'title' => 'T',
            'type' => 'blog',
            'published_at' => $unix,
        ], 'cast_article');

        $resource = $serializer->serialize($entity);

        $this->assertIsString($resource->attributes['published_at']);
        $this->assertStringContainsString('2024-03-04', $resource->attributes['published_at']);
    }

    #[Test]
    public function serializeOmitsAnAccessorDeniedProtectedFieldInsteadOfThrowing(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('article', 'blog', [
            new FieldDefinition('migration_note', 'string', targetEntityTypeId: 'article', targetBundle: 'blog', read: FieldReadLevel::Protected),
        ]);
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));
        ContentEntityBase::setFieldRegistry($registry);
        $scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $scope,
            static fn(): AccessResult => AccessResult::forbidden('Fixture denies the Protected field.'),
        ));

        try {
            $entity = new TestEntity([
                'id' => 1,
                'uuid' => 'uuid-protected',
                'title' => 'Visible',
                'type' => 'blog',
                'migration_note' => 'must stay sealed',
            ]);
            $principal = new AuthorizationPrincipal(7, true, [], ['access content'], 'serializer-test');
            $resource = $scope->run(
                $principal,
                fn() => (new ResourceSerializer($manager))->serialize($entity, new EntityAccessHandler([]), $principal),
            );

            self::assertSame('Visible', $resource->attributes['title']);
            self::assertArrayNotHasKey('migration_note', $resource->attributes);
        } finally {
            EntityReadRuntime::installGuard(null);
            ContentEntityBase::setFieldRegistry(null);
        }
    }
}
