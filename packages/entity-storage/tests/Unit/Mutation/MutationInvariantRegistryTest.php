<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Mutation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\Mutation\EntityMutationInvariantContext;
use Waaseyaa\EntityStorage\Mutation\EntityMutationInvariantInterface;
use Waaseyaa\EntityStorage\Mutation\EntityMutationOperation;
use Waaseyaa\EntityStorage\Mutation\MutationInvariantRegistry;

final class MutationInvariantRegistryTest extends TestCase
{
    #[Test]
    public function contextCarriesItsTypedReferencesAndIsImmutable(): void
    {
        $original = $this->createStub(EntityInterface::class);
        $proposed = $this->createStub(EntityInterface::class);
        $principal = $this->createStub(AuthorizationPrincipalInterface::class);
        $database = $this->createStub(DatabaseInterface::class);

        $context = new EntityMutationInvariantContext(
            EntityMutationOperation::Update,
            $original,
            $proposed,
            $principal,
            $database,
            'transaction-1',
        );

        self::assertSame(EntityMutationOperation::Update, $context->operation);
        self::assertSame($original, $context->original);
        self::assertSame($proposed, $context->proposed);
        self::assertSame($principal, $context->principal);
        self::assertSame($database, $context->database);
        self::assertSame('transaction-1', $context->transactionIdentity);
        self::assertTrue((new \ReflectionClass($context))->isReadOnly());
    }

    #[Test]
    public function contextRejectsBlankTransactionIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EntityMutationInvariantContext(
            EntityMutationOperation::Create,
            null,
            null,
            null,
            $this->createStub(DatabaseInterface::class),
            " \t\n",
        );
    }

    #[Test]
    public function operationEnumContainsTheRequiredVocabulary(): void
    {
        self::assertSame(
            ['create', 'update', 'delete', 'revision', 'translation', 'publication', 'maintenance'],
            array_map(static fn (EntityMutationOperation $operation): string => $operation->value, EntityMutationOperation::cases()),
        );
    }

    #[Test]
    public function registryReturnsRegisteredInvariantAndRefusesDuplicatesAndMissingIds(): void
    {
        $registry = new MutationInvariantRegistry();
        $invariant = $this->createStub(EntityMutationInvariantInterface::class);
        $registry->register('engagement.comment', $invariant);

        self::assertSame($invariant, $registry->get('engagement.comment'));

        try {
            $registry->register('engagement.comment', $invariant);
            self::fail('Duplicate invariant registration was accepted.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\OutOfBoundsException::class);
        $registry->get('engagement.missing');
    }

    #[Test]
    public function registryRejectsBlankIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MutationInvariantRegistry())->register(
            '  ',
            $this->createStub(EntityMutationInvariantInterface::class),
        );
    }
}
