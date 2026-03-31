<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\GraphQL;

use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;
use Waaseyaa\GraphQL\Schema\SchemaFactory;

/**
 * Base for integration tests that assert GraphQL schema shape from registered
 * {@see EntityType} definitions via {@see SchemaFactory}, without a full kernel boot.
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
        $accessHandler = new EntityAccessHandler([]);
        $account = $this->createStub(AccountInterface::class);
        $guard = new GraphQlAccessGuard($accessHandler, $account);
        $resolver = new EntityResolver($this->entityTypeManager, $guard);
        $referenceLoader = new ReferenceLoader($this->entityTypeManager, $guard);
        $factory = new SchemaFactory(
            entityTypeManager: $this->entityTypeManager,
            entityResolver: $resolver,
            referenceLoader: $referenceLoader,
        );

        return $factory->build();
    }

    protected function unwrapTypeName(Type $type): string
    {
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }

        return $type->name;
    }
}
