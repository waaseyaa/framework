<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\ContextAwareAccessPolicyInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Access\Policy\RevisionPolicyComposition;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProjectedProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\RevisionableEntityInterface;

#[CoversClass(EntityAccessHandler::class)]
final class ViewRevisionOperationTest extends TestCase
{
    #[Test]
    public function view_revision_is_a_recognized_operation(): void
    {
        self::assertContains(GateInterface::VIEW_REVISION, EntityAccessHandler::RECOGNIZED_OPERATIONS);
    }

    #[Test]
    public function view_revision_falls_back_to_view_when_the_policy_is_neutral(): void
    {
        $seen = [];
        $policy = $this->policy(static function (EntityInterface $entity, string $operation) use (&$seen): AccessResult {
            $seen[] = [$operation, $entity->label()];

            return $operation === 'view' ? AccessResult::allowed('can view') : AccessResult::neutral();
        });
        $handler = new EntityAccessHandler([$policy]);
        $revision = $this->revision('Historical');

        $result = $handler->check($revision, GateInterface::VIEW_REVISION, $this->account());

        self::assertTrue($result->isAllowed());
        self::assertSame(
            [[RevisionPolicyComposition::OPERATION_VIEW_REVISION, 'Historical'], ['view', 'Historical']],
            $seen,
        );
    }

    #[Test]
    public function an_explicit_view_revision_forbidden_does_not_fall_back_to_view(): void
    {
        $seen = [];
        $policy = $this->policy(static function (EntityInterface $entity, string $operation) use (&$seen): AccessResult {
            $seen[] = [$operation, $entity->label()];
            if ($operation === GateInterface::VIEW_REVISION) {
                return AccessResult::forbidden('historical locked');
            }

            return AccessResult::allowed('current view');
        });
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->revision('Historical'), GateInterface::VIEW_REVISION, $this->account());

        self::assertTrue($result->isForbidden());
        self::assertSame([[GateInterface::VIEW_REVISION, 'Historical']], $seen);
    }

    #[Test]
    public function view_revision_preserves_context_for_primary_and_fallback_policy_decisions(): void
    {
        $seen = [];
        $policy = new class ($seen) implements AccessPolicyInterface, ContextAwareAccessPolicyInterface {
            /** @var list<array{string, array<string, mixed>}> */
            private array $seen;

            /** @param list<array{string, array<string, mixed>}> $seen */
            public function __construct(array &$seen)
            {
                $this->seen = &$seen;
            }

            public function appliesTo(string $entityTypeId): bool { return true; }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                throw new \LogicException('Context-aware policy was invoked through the context-free boundary.');
            }

            public function accessWithContext(
                EntityInterface $entity,
                string $operation,
                AccountInterface $account,
                array $context,
            ): AccessResult {
                $this->seen[] = [$operation, $context];

                return $operation === GateInterface::VIEW_REVISION
                    ? AccessResult::neutral()
                    : AccessResult::allowed('snapshot visible');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check(
            $this->revision('Historical'),
            GateInterface::VIEW_REVISION,
            $this->account(),
            ['purpose' => 'history'],
        );

        self::assertTrue($result->isAllowed());
        self::assertSame([
            [GateInterface::VIEW_REVISION, ['purpose' => 'history']],
            [GateInterface::VIEW, ['purpose' => 'history']],
        ], $seen);
    }

    #[Test]
    public function protected_snapshot_read_authority_can_forbid_a_legacy_view_revision_allowance(): void
    {
        $policy = new ViewRevisionProtectedProvider();
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check(
            $this->frameworkRevision(status: false),
            GateInterface::VIEW_REVISION,
            $this->account(),
        );

        self::assertTrue($result->isForbidden());
        self::assertSame([false], $policy->protectedStatuses);
    }

    /**
     * @param callable(EntityInterface, string, AccountInterface): AccessResult $access
     */
    private function policy(callable $access): AccessPolicyInterface
    {
        return new class ($access) implements AccessPolicyInterface {
            public function __construct(private readonly mixed $access) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return ($this->access)($entity, $operation, $account);
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };
    }

    private function revision(string $title): EntityInterface&RevisionableEntityInterface
    {
        $entity = $this->createStub(RevisionableEntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('article');
        $entity->method('bundle')->willReturn('article');
        $entity->method('label')->willReturn($title);

        return $entity;
    }

    private function account(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(7, true, ['authenticated'], [], 'test');
    }

    private function frameworkRevision(bool $status): ViewRevisionEntity
    {
        $values = ['id' => 1, 'title' => 'Historical', 'status' => $status];
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: $values,
            layout: new EntityReadLayout(new EntityReadLayoutGeneration(), [
                'id' => FieldReadLevel::Public,
                'title' => FieldReadLevel::Public,
                'status' => FieldReadLevel::Public,
            ]),
            structure: new EntityStructure(
                'article',
                'article',
                1,
                null,
                revisionId: 1,
                fieldNames: array_keys($values),
            ),
            entityTypeId: 'article',
            entityKeys: ['id' => 'id', 'label' => 'title', 'revision' => 'revision_id'],
        );
        $entity = $boundary->installer()->instantiate(ViewRevisionEntity::class, $payload);
        self::assertInstanceOf(ViewRevisionEntity::class, $entity);

        return $entity;
    }
}

final class ViewRevisionEntity extends EntityBase {}

final class ViewRevisionProtectedProvider implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    /** @var list<bool> */
    public array $protectedStatuses = [];

    public function appliesTo(string $entityTypeId): bool { return $entityTypeId === 'article'; }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('legacy policy allows');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function protectedEntityReadPolicy(): ProjectedProtectedEntityReadPolicyInterface
    {
        return new class ($this->protectedStatuses) implements ProjectedProtectedEntityReadPolicyInterface {
            /** @var list<bool> */
            private array $seen;

            /** @param list<bool> $seen */
            public function __construct(array &$seen)
            {
                $this->seen = &$seen;
            }

            public function authorizationInputs(): array { return ['status']; }

            public function access(
                \Waaseyaa\Access\AuthorizationPrincipalInterface $principal,
                EntityStructure $structure,
                PolicySubjectViewInterface $subject,
                string $operation,
            ): AccessResult {
                $status = $subject->get('status');
                $this->seen[] = (bool) $status;

                return $status === true
                    ? AccessResult::allowed('visible')
                    : AccessResult::forbidden('historical snapshot sealed');
            }
        };
    }

    public function protectedFieldReadPolicy(): ?ProtectedFieldReadPolicyInterface { return null; }
}
