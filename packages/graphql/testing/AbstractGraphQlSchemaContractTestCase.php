<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Testing;

use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\Schema\SchemaFactory;

/**
 * Base for integration tests that assert GraphQL schema shape from registered
 * {@see EntityType} definitions via {@see SchemaFactory}, without a full kernel boot.
 *
 * @internal
 */
abstract class AbstractGraphQlSchemaContractTestCase extends TestCase
{
    protected EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->registerEntityTypes($this->entityTypeManager);
    }

    abstract protected function registerEntityTypes(EntityTypeManager $entityTypeManager): void;

    protected function buildSchema(): Schema
    {
        // R12: SchemaFactory holds no per-request collaborators (the built
        // Schema is cached across requests/accounts), only entityTypeManager
        // is needed to build the structural schema shape.
        $factory = new SchemaFactory(entityTypeManager: $this->entityTypeManager);

        return $factory->build();
    }

    protected function unwrapTypeName(Type $type): string
    {
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }

        assert($type instanceof \GraphQL\Type\Definition\NamedType);

        return $type->name();
    }
}
