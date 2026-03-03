<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Gate;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\AccessDeniedException;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityAccessGate::class)]
final class EntityAccessGateTest extends TestCase
{
    // --- Interface ---

    #[Test]
    public function implementsGateInterface(): void
    {
        $handler = new EntityAccessHandler();
        $gate = new EntityAccessGate($handler);
        $this->assertInstanceOf(GateInterface::class, $gate);
    }

    // --- allows() with entity subject ---

    #[Test]
    public function allowsWithEntitySubjectDelegatesToHandler(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertTrue($gate->allows('view', $entity, $account));
    }

    #[Test]
    public function deniesWithEntitySubjectWhenPolicyReturnsNeutral(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount([]);
        $policy = $this->createPolicy('node', AccessResult::neutral());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('view', $entity, $account));
    }

    #[Test]
    public function deniesWithEntitySubjectWhenPolicyReturnsForbidden(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node', AccessResult::forbidden('Explicitly forbidden.'));
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('view', $entity, $account));
    }

    // --- allows() with string subject (create access) ---

    #[Test]
    public function allowsCreateWithStringSubjectDelegatesToCreateAccess(): void
    {
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node_type', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertTrue($gate->allows('create', 'node_type', $account));
    }

    #[Test]
    public function deniesCreateWithStringSubjectWhenPolicyReturnsNeutral(): void
    {
        $account = $this->createAccount([]);
        $policy = $this->createPolicy('node_type', AccessResult::neutral());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('create', 'node_type', $account));
    }

    // --- allows() with string subject, non-create ability ---

    #[Test]
    public function deniesNonCreateAbilityWithStringSubject(): void
    {
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        // Can't check instance-level access without an entity.
        $this->assertFalse($gate->allows('view', 'node', $account));
    }

    // --- allows() without account ---

    #[Test]
    public function deniesWhenUserIsNull(): void
    {
        $entity = $this->createEntity('node');
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('view', $entity));
    }

    #[Test]
    public function deniesWhenUserIsNotAccountInterface(): void
    {
        $entity = $this->createEntity('node');
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('view', $entity, new \stdClass()));
    }

    // --- denies() ---

    #[Test]
    public function deniesIsInverseOfAllows(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->denies('view', $entity, $account));
    }

    #[Test]
    public function deniesReturnsTrueWhenAccessDenied(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount([]);
        $policy = $this->createPolicy('node', AccessResult::neutral());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertTrue($gate->denies('view', $entity, $account));
    }

    // --- authorize() ---

    #[Test]
    public function authorizeDoesNotThrowWhenAllowed(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $gate->authorize('view', $entity, $account);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function authorizeThrowsWhenDenied(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount([]);
        $policy = $this->createPolicy('node', AccessResult::neutral());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        try {
            $gate->authorize('view', $entity, $account);
            $this->fail('Expected AccessDeniedException was not thrown.');
        } catch (AccessDeniedException $e) {
            $this->assertSame('view', $e->ability);
            $this->assertSame($entity, $e->subject);
        }
    }

    // --- Policy exception handling ---

    #[Test]
    public function deniesWhenPolicyThrowsException(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount(['administrator']);

        $policy = $this->createMock(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturn(true);
        $policy->method('access')->willThrowException(new \RuntimeException('Database unavailable'));

        $handler = new EntityAccessHandler([$policy]);
        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('view', $entity, $account));
    }

    // --- Unsupported subject types ---

    #[Test]
    public function deniesWithUnsupportedSubjectType(): void
    {
        $handler = new EntityAccessHandler();
        $gate = new EntityAccessGate($handler);
        $account = $this->createAccount(['administrator']);

        $this->assertFalse($gate->allows('view', 42, $account));
    }

    // --- Helpers ---

    private function createEntity(string $typeId): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($typeId);
        $entity->method('bundle')->willReturn($typeId);
        return $entity;
    }

    private function createAccount(array $roles): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('getRoles')->willReturn($roles);
        return $account;
    }

    private function createPolicy(string $entityTypeId, AccessResult $result): AccessPolicyInterface
    {
        $policy = $this->createMock(AccessPolicyInterface::class);
        $policy->method('appliesTo')
            ->willReturnCallback(fn(string $type) => $type === $entityTypeId);
        $policy->method('access')->willReturn($result);
        $policy->method('createAccess')->willReturn($result);
        return $policy;
    }
}
