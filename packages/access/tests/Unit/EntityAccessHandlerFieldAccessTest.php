<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

#[CoversClass(EntityAccessHandler::class)]
final class EntityAccessHandlerFieldAccessTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createEntity(string $typeId = 'node'): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($typeId);
        return $entity;
    }

    private function createAccount(): AccountInterface
    {
        return $this->createMock(AccountInterface::class);
    }

    /**
     * Creates a policy implementing both interfaces.
     */
    private function createFieldPolicy(
        string $entityTypeId,
        AccessResult $fieldResult,
    ): AccessPolicyInterface&FieldAccessPolicyInterface {
        return new class ($entityTypeId, $fieldResult) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly AccessResult $fieldResult,
            ) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return $this->fieldResult;
            }
        };
    }

    /**
     * Creates a field policy with per-field logic.
     */
    private function createConditionalFieldPolicy(
        string $entityTypeId,
        string $targetField,
        string $targetOperation,
        AccessResult $result,
    ): AccessPolicyInterface&FieldAccessPolicyInterface {
        return new class ($entityTypeId, $targetField, $targetOperation, $result) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly string $targetField,
                private readonly string $targetOperation,
                private readonly AccessResult $result,
            ) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === $this->targetField && $operation === $this->targetOperation) {
                    return $this->result;
                }
                return AccessResult::neutral();
            }
        };
    }

    // ---------------------------------------------------------------
    // checkFieldAccess() tests
    // ---------------------------------------------------------------

    #[Test]
    public function checkFieldAccessNoPoliciesReturnsNeutral(): void
    {
        $handler = new EntityAccessHandler();
        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'body',
            'view',
            $this->createAccount(),
        );

        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function checkFieldAccessSkipsPoliciesWithoutFieldInterface(): void
    {
        // A policy that only implements AccessPolicyInterface (no field access).
        $entityOnlyPolicy = $this->createMock(AccessPolicyInterface::class);
        $entityOnlyPolicy->method('appliesTo')->willReturn(true);

        $handler = new EntityAccessHandler([$entityOnlyPolicy]);
        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'body',
            'view',
            $this->createAccount(),
        );

        // Should be Neutral since the only policy doesn't implement FieldAccessPolicyInterface.
        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function checkFieldAccessAllowed(): void
    {
        $policy = $this->createFieldPolicy('node', AccessResult::allowed('has permission'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'body',
            'view',
            $this->createAccount(),
        );

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function checkFieldAccessForbidden(): void
    {
        $policy = $this->createFieldPolicy('node', AccessResult::forbidden('secret field'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'secret',
            'view',
            $this->createAccount(),
        );

        $this->assertTrue($result->isForbidden());
        $this->assertSame('secret field', $result->reason);
    }

    #[Test]
    public function checkFieldAccessForbiddenWinsOverAllowed(): void
    {
        $handler = new EntityAccessHandler([
            $this->createFieldPolicy('node', AccessResult::allowed('yes')),
            $this->createFieldPolicy('node', AccessResult::forbidden('no')),
        ]);

        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'status',
            'edit',
            $this->createAccount(),
        );

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function checkFieldAccessFiltersByEntityType(): void
    {
        // Policy applies to 'user', not 'node'.
        $policy = $this->createFieldPolicy('user', AccessResult::forbidden('should not apply'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->checkFieldAccess(
            $this->createEntity('node'),
            'body',
            'view',
            $this->createAccount(),
        );

        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function checkFieldAccessForbiddenShortCircuits(): void
    {
        $handler = new EntityAccessHandler([
            $this->createFieldPolicy('node', AccessResult::forbidden('stop')),
            $this->createFieldPolicy('node', AccessResult::allowed('go')),
        ]);

        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'status',
            'edit',
            $this->createAccount(),
        );

        $this->assertTrue($result->isForbidden());
        $this->assertSame('stop', $result->reason);
    }

    #[Test]
    public function checkFieldAccessPassesFieldNameAndOperation(): void
    {
        $policy = $this->createConditionalFieldPolicy('node', 'status', 'edit', AccessResult::forbidden('no edit status'));
        $handler = new EntityAccessHandler([$policy]);

        // status + edit → forbidden
        $result = $handler->checkFieldAccess($this->createEntity(), 'status', 'edit', $this->createAccount());
        $this->assertTrue($result->isForbidden());

        // status + view → neutral (no match)
        $result = $handler->checkFieldAccess($this->createEntity(), 'status', 'view', $this->createAccount());
        $this->assertTrue($result->isNeutral());

        // body + edit → neutral (no match)
        $result = $handler->checkFieldAccess($this->createEntity(), 'body', 'edit', $this->createAccount());
        $this->assertTrue($result->isNeutral());
    }

    // ---------------------------------------------------------------
    // filterFields() tests
    // ---------------------------------------------------------------

    #[Test]
    public function filterFieldsReturnsAllWhenNoPolicies(): void
    {
        $handler = new EntityAccessHandler();
        $fields = $handler->filterFields(
            $this->createEntity(),
            ['title', 'body', 'status'],
            'view',
            $this->createAccount(),
        );

        $this->assertSame(['title', 'body', 'status'], $fields);
    }

    #[Test]
    public function filterFieldsRemovesForbiddenFields(): void
    {
        $policy = $this->createConditionalFieldPolicy('node', 'secret', 'view', AccessResult::forbidden('hidden'));
        $handler = new EntityAccessHandler([$policy]);

        $fields = $handler->filterFields(
            $this->createEntity(),
            ['title', 'body', 'secret', 'status'],
            'view',
            $this->createAccount(),
        );

        $this->assertSame(['title', 'body', 'status'], $fields);
    }

    #[Test]
    public function filterFieldsUsesCorrectOperation(): void
    {
        // Forbid editing 'status', but viewing is fine.
        $policy = $this->createConditionalFieldPolicy('node', 'status', 'edit', AccessResult::forbidden('no'));
        $handler = new EntityAccessHandler([$policy]);

        $viewFields = $handler->filterFields($this->createEntity(), ['title', 'status'], 'view', $this->createAccount());
        $editFields = $handler->filterFields($this->createEntity(), ['title', 'status'], 'edit', $this->createAccount());

        $this->assertSame(['title', 'status'], $viewFields);
        $this->assertSame(['title'], $editFields);
    }
}
