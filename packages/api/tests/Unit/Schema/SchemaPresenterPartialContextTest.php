<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Exception\PartialAccessContextException;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Negative-path coverage for the paired-nullable access-context invariant on
 * `SchemaPresenter::present()`.
 *
 * Mission #824 WP05 surface C (closes #844). Mirrors
 * ResourceSerializerPartialContextTest from surface B; both methods share the
 * same `(?EntityAccessHandler, ?AccountInterface)` precondition and throw the
 * same typed exception when handed mixed nullability.
 */
#[CoversClass(SchemaPresenter::class)]
final class SchemaPresenterPartialContextTest extends TestCase
{
    private SchemaPresenter $presenter;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->presenter = new SchemaPresenter();
        $this->entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );
    }

    #[Test]
    public function presentAcceptsBothNullContext(): void
    {
        $schema = $this->presenter->present($this->entityType);

        self::assertSame('article', $schema['x-entity-type']);
    }

    #[Test]
    public function presentAcceptsBothNonNullContext(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('article');
        $schema = $this->presenter->present(
            $this->entityType,
            [],
            $entity,
            new EntityAccessHandler([]),
            $this->createStub(AccountInterface::class),
        );

        self::assertSame('article', $schema['x-entity-type']);
    }

    #[Test]
    public function presentRejectsAccessContextWithoutASubjectEntity(): void
    {
        $this->expectException(PartialAccessContextException::class);

        $this->presenter->present(
            $this->entityType,
            [],
            null,
            new EntityAccessHandler([]),
            $this->createStub(AccountInterface::class),
        );
    }

    #[Test]
    public function presentRejectsHandlerWithoutAccount(): void
    {
        $this->expectException(PartialAccessContextException::class);
        $this->expectExceptionMessageMatches('/^\[PARTIAL_ACCESS_CONTEXT\]/');

        $this->presenter->present(
            $this->entityType,
            [],
            null,
            new EntityAccessHandler([]),
            null,
        );
    }

    #[Test]
    public function presentRejectsAccountWithoutHandler(): void
    {
        $this->expectException(PartialAccessContextException::class);
        $this->expectExceptionMessageMatches('/^\[PARTIAL_ACCESS_CONTEXT\]/');

        $this->presenter->present(
            $this->entityType,
            [],
            null,
            null,
            $this->createStub(AccountInterface::class),
        );
    }

    #[Test]
    public function entityTypeInterfaceParameterIsNotTouchedBeforeGuardFires(): void
    {
        // Sanity: even an interface mock with no expectations passes the guard
        // because the precondition check runs first.
        $entityType = $this->createStub(EntityTypeInterface::class);

        $this->expectException(PartialAccessContextException::class);

        $this->presenter->present(
            $entityType,
            [],
            null,
            new EntityAccessHandler([]),
            null,
        );
    }
}
