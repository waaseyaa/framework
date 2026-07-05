<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Schema;

use GraphQL\Type\Definition\ObjectType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\Schema\EntityTypeBuilder;
use Waaseyaa\GraphQL\Schema\SchemaFactory;
use Waaseyaa\GraphQL\Tests\Fixtures\AttributeFirstEntities\UserSecretSchemaFixture;

require_once __DIR__ . '/../../Fixtures/AttributeFirstEntities/UserSecretSchemaFixture.php';

/**
 * Verifies that EntityTypeBuilder::buildOutputFields() drops fields that must
 * never be queryable or discoverable via GraphQL introspection:
 *
 *   1. Fields whose FieldDefinition carries `settings['internal'] => true`
 *      (e.g. `two_factor_secret` — same predicate as ResourceSerializer).
 *   2. Fields whose name is in the ALWAYS_INTERNAL_FIELDS credential list
 *      (`pass`, `password`, `password_hash`) — defense in depth, same list
 *      as ResourceSerializer::ALWAYS_INTERNAL_FIELDS.
 *
 * Both categories are a SCHEMA-LEVEL DROP: the field is absent from the
 * built ObjectType's field map, so it is neither queryable nor disclosed
 * by introspection (`__schema` / `__type` queries). The ObjectType field map
 * IS the introspection source, so an assertion on `hasField()` covers both
 * the data path and the introspection path.
 */
#[CoversClass(EntityTypeBuilder::class)]
final class EntityTypeBuilderInternalFieldTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        SchemaFactory::resetCache();
        EntityType::clearFromClassCache();

        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerCoreEntityType(
            EntityType::fromClass(UserSecretSchemaFixture::class),
        );
    }

    private function buildOutputObjectType(): ObjectType
    {
        // R12: SchemaFactory holds no per-request collaborators (the built
        // Schema is cached across requests/accounts), only entityTypeManager
        // is needed to build the structural schema shape.
        $factory = new SchemaFactory(entityTypeManager: $this->entityTypeManager);

        $schema = $factory->build();
        $queryType = $schema->getQueryType();
        self::assertNotNull($queryType);

        $field = $queryType->getField('secretUser');
        $type = $field->getType();
        self::assertInstanceOf(ObjectType::class, $type);

        return $type;
    }

    #[Test]
    public function normalFieldIsPresentInOutputType(): void
    {
        $objectType = $this->buildOutputObjectType();

        self::assertTrue(
            $objectType->hasField('display_name'),
            'A normal (non-internal) field must appear in the GraphQL output schema.',
        );
    }

    #[Test]
    public function fieldWithInternalSettingIsAbsentFromOutputType(): void
    {
        $objectType = $this->buildOutputObjectType();

        self::assertFalse(
            $objectType->hasField('two_factor_secret'),
            "A field with settings['internal' => true] must be dropped from the GraphQL output schema "
            . '(both queryable and introspectable). Pre-fix: this field was present.',
        );
    }

    #[Test]
    public function credentialNamedFieldPasswordIsAbsentFromOutputType(): void
    {
        $objectType = $this->buildOutputObjectType();

        self::assertFalse(
            $objectType->hasField('password'),
            "A field named 'password' (ALWAYS_INTERNAL_FIELDS) must be dropped from the GraphQL output schema. "
            . 'Pre-fix: this field was present.',
        );
    }

    #[Test]
    public function credentialNamedFieldPasswordHashIsAbsentFromOutputType(): void
    {
        $objectType = $this->buildOutputObjectType();

        self::assertFalse(
            $objectType->hasField('password_hash'),
            "A field named 'password_hash' (ALWAYS_INTERNAL_FIELDS) must be dropped from the GraphQL output schema. "
            . 'Pre-fix: this field was present.',
        );
    }
}
