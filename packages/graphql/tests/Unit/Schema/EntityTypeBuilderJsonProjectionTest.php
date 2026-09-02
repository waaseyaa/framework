<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Schema;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\GraphQL\Schema\EntityTypeBuilder;
use Waaseyaa\GraphQL\Schema\SchemaFactory;

#[CoversClass(EntityTypeBuilder::class)]
final class EntityTypeBuilderJsonProjectionTest extends TestCase
{
    protected function tearDown(): void
    {
        SchemaFactory::resetCache();
    }

    #[Test]
    public function nativeJsonValuesAreEncodedForTheGraphQlStringAdapter(): void
    {
        SchemaFactory::resetCache();
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerCoreEntityType(TestEntityType::stub(
            'json_projection',
            [
                'id' => new FieldDefinition(name: 'id', type: 'integer'),
                'uuid' => new FieldDefinition(name: 'uuid', type: 'string'),
                'payload' => new FieldDefinition(name: 'payload', type: 'json'),
            ],
            keys: TestEntity::definitionKeys(),
            class: TestEntity::class,
            label: 'JSON projection',
        ));

        $query = new SchemaFactory(entityTypeManager: $manager)->build()->getQueryType();
        self::assertNotNull($query);
        $entityType = $query->getField('jsonProjection')->getType();
        self::assertInstanceOf(ObjectType::class, $entityType);
        $field = $entityType->getField('payload');
        self::assertSame(Type::string(), $field->getType());
        self::assertNotNull($field->resolveFn);

        self::assertSame(
            '{"count":2,"names":["Aaniin","Tansi"]}',
            ($field->resolveFn)(['payload' => ['count' => 2, 'names' => ['Aaniin', 'Tansi']]]),
        );
        self::assertSame('{"already":"encoded"}', ($field->resolveFn)(['payload' => '{"already":"encoded"}']));
        self::assertNull(($field->resolveFn)(['payload' => null]));
    }
}
