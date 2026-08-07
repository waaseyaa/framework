<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\AccessDeniedException;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;

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

    // --- allows() without account: null resolves to the current/anonymous principal ---

    #[Test]
    public function nullUserResolvesToAnonymousPrincipalWhenNoContextIsWired(): void
    {
        $entity = $this->createEntity('node');
        $seen = null;
        $policy = $this->createCapturingPolicy('node', $seen);
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertTrue($gate->allows('view', $entity));
        $this->assertInstanceOf(AuthorizationPrincipalInterface::class, $seen);
        $this->assertSame(0, $seen->id());
        $this->assertFalse($seen->isAuthenticated());
    }

    #[Test]
    public function nullUserUsesFieldReadScopePrincipalWhenAvailable(): void
    {
        $entity = $this->createEntity('node');
        $seen = null;
        $policy = $this->createCapturingPolicy('node', $seen);
        $handler = new EntityAccessHandler([$policy]);

        $scope = new AccountFieldReadScope();
        $principal = new AuthorizationPrincipal(42, true, ['editor'], [], 'gen-1');

        $gate = new EntityAccessGate($handler, fieldReadScope: $scope);

        $allowed = $scope->run($principal, fn(): bool => $gate->allows('view', $entity));

        $this->assertTrue($allowed);
        $this->assertSame($principal, $seen);
    }

    #[Test]
    public function nullUserUsesAccountContextPrincipalWhenScopeIsEmpty(): void
    {
        $entity = $this->createEntity('node');
        $seen = null;
        $policy = $this->createCapturingPolicy('node', $seen);
        $handler = new EntityAccessHandler([$policy]);

        $context = new RequestAccountContext();
        $principal = new AuthorizationPrincipal(7, true, [], [], 'gen-1');
        $context->set($principal);

        $gate = new EntityAccessGate($handler, fieldReadScope: new AccountFieldReadScope(), accountContext: $context);

        $this->assertTrue($gate->allows('view', $entity));
        $this->assertSame($principal, $seen);
    }

    #[Test]
    public function nullUserIgnoresEntityBackedContextAccountAndFallsBackToAnonymous(): void
    {
        $entity = $this->createEntity('node');
        $seen = null;
        $policy = $this->createCapturingPolicy('node', $seen);
        $handler = new EntityAccessHandler([$policy]);

        // Entity-backed accounts must never reach a policy directly (they
        // cross the audited principal factory) — mirror DecisionAccountResolver.
        $entityAccount = $this->createEntityBackedAccount();
        $context = new RequestAccountContext();
        $context->set($entityAccount);

        $gate = new EntityAccessGate($handler, accountContext: $context);

        $this->assertTrue($gate->allows('view', $entity));
        $this->assertInstanceOf(AuthorizationPrincipalInterface::class, $seen);
        $this->assertNotSame($entityAccount, $seen);
        $this->assertSame(0, $seen->id());
        $this->assertFalse($seen->isAuthenticated());
    }

    #[Test]
    public function authorizeWithNullUserDoesNotThrowWhenAnonymousViewIsAllowed(): void
    {
        $entity = $this->createEntity('node');
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $gate->authorize('view', $entity);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function deniesWhenUserIsNotAccountInterface(): void
    {
        $entity = $this->createEntity('node');
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $logger = $this->createSpyLogger();
        $gate = new EntityAccessGate($handler, $logger);

        $this->assertFalse($gate->allows('view', $entity, new \stdClass()));
        $this->assertSame([LogLevel::WARNING], array_column($logger->records, 0));
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

        $policy = $this->createStub(AccessPolicyInterface::class);
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
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($typeId);
        $entity->method('bundle')->willReturn($typeId);
        return $entity;
    }

    private function createAccount(array $roles): AuthorizationPrincipalInterface
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('getRoles')->willReturn($roles);
        return $account;
    }

    private function createPolicy(string $entityTypeId, AccessResult $result): AccessPolicyInterface
    {
        $policy = $this->createStub(AccessPolicyInterface::class);
        $policy->method('appliesTo')
            ->willReturnCallback(fn(string $type) => $type === $entityTypeId);
        $policy->method('access')->willReturn($result);
        $policy->method('createAccess')->willReturn($result);
        return $policy;
    }

    /**
     * Policy that allows every op and captures the account it was called with.
     */
    private function createCapturingPolicy(string $entityTypeId, ?AccountInterface &$seen): AccessPolicyInterface
    {
        $policy = $this->createStub(AccessPolicyInterface::class);
        $policy->method('appliesTo')
            ->willReturnCallback(fn(string $type) => $type === $entityTypeId);
        $policy->method('access')->willReturnCallback(
            function (EntityInterface $entity, string $op, AccountInterface $account) use (&$seen): AccessResult {
                $seen = $account;
                return AccessResult::allowed();
            },
        );
        return $policy;
    }

    /**
     * Anonymous class implementing both interfaces — createMock() cannot
     * build intersection doubles when the interfaces share a method (id()).
     */
    private function createEntityBackedAccount(): AuthorizationPrincipalInterface&EntityInterface
    {
        return new class implements AuthorizationPrincipalInterface, EntityInterface {
            public function id(): int|string
            {
                return 42;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['editor'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }

            public function claimsGeneration(): string
            {
                return 'gen-1';
            }

            public function tenantId(): ?string
            {
                return null;
            }

            public function communityId(): ?string
            {
                return null;
            }

            public function uuid(): string
            {
                return 'uuid-42';
            }

            public function label(): string
            {
                return 'Entity-backed account';
            }

            public function getEntityTypeId(): string
            {
                return 'user';
            }

            public function bundle(): string
            {
                return 'user';
            }

            public function isNew(): bool
            {
                return false;
            }

            public function get(string $name): mixed
            {
                return null;
            }

            public function set(string $name, mixed $value): static
            {
                return $this;
            }

            public function toArray(): array
            {
                return [];
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }

    /**
     * @return LoggerInterface&object{records: list<array{LogLevel, string}>}
     */
    private function createSpyLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{LogLevel, string}> */
            public array $records = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
    }
}
