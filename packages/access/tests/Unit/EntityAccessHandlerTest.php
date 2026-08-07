<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccessStatus;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Waaseyaa\Access\EntityAccessHandler
 */
class EntityAccessHandlerTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createEntity(string $typeId = 'node', string $bundle = 'article'): EntityInterface
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($typeId);
        $entity->method('bundle')->willReturn($bundle);

        return $entity;
    }

    private function createAccount(): AccountInterface
    {
        return $this->createStub(AccountInterface::class);
    }

    private function createPolicy(
        string $entityTypeId,
        AccessResult $accessResult,
        ?AccessResult $createResult = null,
    ): AccessPolicyInterface {
        $policy = $this->createStub(AccessPolicyInterface::class);
        $policy->method('appliesTo')
            ->willReturnCallback(fn(string $type) => $type === $entityTypeId);
        $policy->method('access')
            ->willReturn($accessResult);
        $policy->method('createAccess')
            ->willReturn($createResult ?? $accessResult);

        return $policy;
    }

    // ---------------------------------------------------------------
    // check() tests
    // ---------------------------------------------------------------

    public function testNoPoliciesReturnsNeutral(): void
    {
        $handler = new EntityAccessHandler();
        $result = $handler->check($this->createEntity(), 'view', $this->createAccount());

        $this->assertTrue($result->isNeutral());
    }

    public function testSingleAllowedPolicy(): void
    {
        $policy = $this->createPolicy('node', AccessResult::allowed('has permission'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->createEntity(), 'view', $this->createAccount());

        $this->assertTrue($result->isAllowed());
    }

    public function testSingleForbiddenPolicy(): void
    {
        $policy = $this->createPolicy('node', AccessResult::forbidden('blocked'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->createEntity(), 'view', $this->createAccount());

        $this->assertTrue($result->isForbidden());
        $this->assertSame('blocked', $result->reason);
    }

    public function testSingleNeutralPolicy(): void
    {
        $policy = $this->createPolicy('node', AccessResult::neutral());
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->createEntity(), 'view', $this->createAccount());

        $this->assertTrue($result->isNeutral());
    }

    public function testForbiddenWinsOverAllowed(): void
    {
        $handler = new EntityAccessHandler([
            $this->createPolicy('node', AccessResult::allowed('yes')),
            $this->createPolicy('node', AccessResult::forbidden('no')),
        ]);

        $result = $handler->check($this->createEntity(), 'update', $this->createAccount());

        $this->assertTrue($result->isForbidden());
        $this->assertSame('no', $result->reason);
    }

    public function testAllowedWinsOverNeutral(): void
    {
        $handler = new EntityAccessHandler([
            $this->createPolicy('node', AccessResult::neutral()),
            $this->createPolicy('node', AccessResult::allowed('granted')),
        ]);

        $result = $handler->check($this->createEntity(), 'view', $this->createAccount());

        $this->assertTrue($result->isAllowed());
    }

    public function testMultipleNeutralsStayNeutral(): void
    {
        $handler = new EntityAccessHandler([
            $this->createPolicy('node', AccessResult::neutral()),
            $this->createPolicy('node', AccessResult::neutral()),
        ]);

        $result = $handler->check($this->createEntity(), 'delete', $this->createAccount());

        $this->assertTrue($result->isNeutral());
    }

    public function testPolicyFilteredByEntityType(): void
    {
        // Policy only applies to 'user', entity is 'node'.
        $policy = $this->createPolicy('user', AccessResult::forbidden('should not apply'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->createEntity('node'), 'view', $this->createAccount());

        // Should be Neutral since the policy doesn't apply.
        $this->assertTrue($result->isNeutral());
    }

    public function testMixedEntityTypePolicies(): void
    {
        $handler = new EntityAccessHandler([
            $this->createPolicy('user', AccessResult::forbidden('user blocked')),
            $this->createPolicy('node', AccessResult::allowed('node allowed')),
        ]);

        $result = $handler->check($this->createEntity('node'), 'view', $this->createAccount());

        // Only the node policy applies.
        $this->assertTrue($result->isAllowed());
    }

    public function testAddPolicy(): void
    {
        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->createPolicy('node', AccessResult::allowed('added')));

        $result = $handler->check($this->createEntity(), 'view', $this->createAccount());

        $this->assertTrue($result->isAllowed());
    }

    public function testForbiddenShortCircuits(): void
    {
        // First policy forbids — second policy should not matter.
        $forbidPolicy = $this->createPolicy('node', AccessResult::forbidden('stop'));
        $allowPolicy = $this->createPolicy('node', AccessResult::allowed('go'));

        $handler = new EntityAccessHandler([$forbidPolicy, $allowPolicy]);

        $result = $handler->check($this->createEntity(), 'view', $this->createAccount());

        $this->assertTrue($result->isForbidden());
        $this->assertSame('stop', $result->reason);
    }

    // ---------------------------------------------------------------
    // checkCreateAccess() tests
    // ---------------------------------------------------------------

    public function testCreateAccessNoPolicies(): void
    {
        $handler = new EntityAccessHandler();
        $result = $handler->checkCreateAccess('node', 'article', $this->createAccount());

        $this->assertTrue($result->isNeutral());
    }

    public function testCreateAccessAllowed(): void
    {
        $policy = $this->createPolicy(
            'node',
            AccessResult::neutral(),
            AccessResult::allowed('can create'),
        );
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->checkCreateAccess('node', 'article', $this->createAccount());

        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessForbiddenWins(): void
    {
        $handler = new EntityAccessHandler([
            $this->createPolicy('node', AccessResult::neutral(), AccessResult::allowed('yes')),
            $this->createPolicy('node', AccessResult::neutral(), AccessResult::forbidden('no creating')),
        ]);

        $result = $handler->checkCreateAccess('node', 'article', $this->createAccount());

        $this->assertTrue($result->isForbidden());
    }

    public function testCreateAccessFiltersByEntityType(): void
    {
        $policy = $this->createPolicy('user', AccessResult::neutral(), AccessResult::forbidden('not for nodes'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->checkCreateAccess('node', 'article', $this->createAccount());

        $this->assertTrue($result->isNeutral());
    }

    // ---------------------------------------------------------------
    // Operation passthrough
    // ---------------------------------------------------------------

    public function testOperationPassedToPolicy(): void
    {
        $entity = $this->createEntity();
        $account = $this->createAccount();

        $policy = $this->createMock(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturn(true);
        $policy->expects($this->once())
            ->method('access')
            ->with($entity, 'delete', $account)
            ->willReturn(AccessResult::allowed());

        $handler = new EntityAccessHandler([$policy]);
        $handler->check($entity, 'delete', $account);
    }

    public function testCreateAccessPassesBundleAndType(): void
    {
        $account = $this->createAccount();

        $policy = $this->createMock(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturn(true);
        $policy->expects($this->once())
            ->method('createAccess')
            ->with('node', 'page', $account)
            ->willReturn(AccessResult::neutral());

        $handler = new EntityAccessHandler([$policy]);
        $handler->checkCreateAccess('node', 'page', $account);
    }
}
