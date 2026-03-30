<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Abstract contract test for AccessPolicyInterface implementations.
 *
 * Each concrete subclass provides a real policy instance and the entity type
 * it applies to. The contract tests verify that every implementation honours
 * the interface behavioural contract.
 */
#[CoversNothing]
abstract class AccessPolicyContractTest extends TestCase
{
    /**
     * Create the policy under test.
     */
    abstract protected function createPolicy(): AccessPolicyInterface;

    /**
     * The entity type ID this policy applies to.
     */
    abstract protected function getApplicableEntityTypeId(): string;

    /**
     * Create a minimal entity stub for the applicable entity type.
     */
    abstract protected function createEntityStub(): EntityInterface;

    /**
     * Create a minimal account stub.
     */
    protected function createAccountStub(
        int|string $id = 1,
        array $permissions = [],
        array $roles = [],
        bool $authenticated = true,
    ): AccountInterface {
        return new class ($id, $permissions, $roles, $authenticated) implements AccountInterface {
            /** @param string[] $permissions @param string[] $roles */
            public function __construct(
                private readonly int|string $id,
                private readonly array $permissions,
                private readonly array $roles,
                private readonly bool $authenticated,
            ) {}

            public function id(): int|string
            {
                return $this->id;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }
        };
    }

    // -----------------------------------------------------------------
    // appliesTo() contract
    // -----------------------------------------------------------------

    #[Test]
    public function appliesToReturnsTrueForCorrectEntityType(): void
    {
        $policy = $this->createPolicy();

        self::assertTrue($policy->appliesTo($this->getApplicableEntityTypeId()));
    }

    #[Test]
    public function appliesToReturnsFalseForUnrelatedEntityType(): void
    {
        $policy = $this->createPolicy();

        self::assertFalse($policy->appliesTo('completely_unrelated_type_' . bin2hex(random_bytes(4))));
    }

    // -----------------------------------------------------------------
    // access() contract
    // -----------------------------------------------------------------

    #[Test]
    public function accessReturnsAccessResultForViewOperation(): void
    {
        $policy = $this->createPolicy();
        $entity = $this->createEntityStub();
        $account = $this->createAccountStub();

        $result = $policy->access($entity, 'view', $account);

        self::assertInstanceOf(AccessResult::class, $result);
        self::assertAccessResultHasStatusMethods($result);
    }

    #[Test]
    public function accessReturnsAccessResultForUpdateOperation(): void
    {
        $policy = $this->createPolicy();
        $entity = $this->createEntityStub();
        $account = $this->createAccountStub();

        $result = $policy->access($entity, 'update', $account);

        self::assertInstanceOf(AccessResult::class, $result);
        self::assertAccessResultHasStatusMethods($result);
    }

    #[Test]
    public function accessReturnsAccessResultForDeleteOperation(): void
    {
        $policy = $this->createPolicy();
        $entity = $this->createEntityStub();
        $account = $this->createAccountStub();

        $result = $policy->access($entity, 'delete', $account);

        self::assertInstanceOf(AccessResult::class, $result);
        self::assertAccessResultHasStatusMethods($result);
    }

    #[Test]
    public function accessReturnsAccessResultForUnknownOperation(): void
    {
        $policy = $this->createPolicy();
        $entity = $this->createEntityStub();
        $account = $this->createAccountStub();

        $result = $policy->access($entity, 'unknown_operation', $account);

        self::assertInstanceOf(AccessResult::class, $result);
        self::assertAccessResultHasStatusMethods($result);
    }

    // -----------------------------------------------------------------
    // createAccess() contract
    // -----------------------------------------------------------------

    #[Test]
    public function createAccessReturnsAccessResult(): void
    {
        $policy = $this->createPolicy();
        $account = $this->createAccountStub();

        $result = $policy->createAccess(
            $this->getApplicableEntityTypeId(),
            'default',
            $account,
        );

        self::assertInstanceOf(AccessResult::class, $result);
        self::assertAccessResultHasStatusMethods($result);
    }

    #[Test]
    public function createAccessWithUnauthenticatedAccountReturnsAccessResult(): void
    {
        $policy = $this->createPolicy();
        $account = $this->createAccountStub(id: 0, authenticated: false);

        $result = $policy->createAccess(
            $this->getApplicableEntityTypeId(),
            'default',
            $account,
        );

        self::assertInstanceOf(AccessResult::class, $result);
        self::assertAccessResultHasStatusMethods($result);
    }

    // -----------------------------------------------------------------
    // AccessResult status methods contract
    // -----------------------------------------------------------------

    #[Test]
    public function accessResultStatusMethodsAreMutuallyExclusive(): void
    {
        $policy = $this->createPolicy();
        $entity = $this->createEntityStub();
        $account = $this->createAccountStub();

        $result = $policy->access($entity, 'view', $account);

        $trueCount = (int) $result->isAllowed()
            + (int) $result->isNeutral()
            + (int) $result->isForbidden()
            + (int) $result->isUnauthenticated();

        self::assertSame(1, $trueCount, 'Exactly one status method must return true.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private static function assertAccessResultHasStatusMethods(AccessResult $result): void
    {
        // Verify that all status query methods exist and return bool.
        self::assertIsBool($result->isAllowed());
        self::assertIsBool($result->isNeutral());
        self::assertIsBool($result->isForbidden());
        self::assertIsBool($result->isUnauthenticated());
    }
}
