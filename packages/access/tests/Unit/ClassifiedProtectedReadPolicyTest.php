<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Attribute\AccessPolicy as AccessPolicyAttribute;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\ClassifiedProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\CompiledPolicySubjectView;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;

final class ClassifiedProtectedReadPolicyTest extends TestCase
{
    #[Test]
    public function application_classification_inputs_are_planned_and_missing_inputs_deny(): void
    {
        $policy = new ClassifiedPolicyProvider(new ApplicationPageReadPolicy());
        $handler = new EntityAccessHandler([$policy]);

        $plan = $handler->protectedEntityReadProjectionPlan('page', 'page');
        self::assertNotNull($plan);
        self::assertSame(['parent_id', 'slug'], $plan->authorizationInputs);

        $principal = new AuthorizationPrincipal(7, true, ['editor'], [], 'test');
        $structure = new EntityStructure('page', 'page', 1);
        $missing = $plan->access($principal, $structure, new CompiledPolicySubjectView(['slug' => 'rht']), 'view');
        self::assertTrue($missing->isForbidden());

        $denied = $plan->access($principal, $structure, new CompiledPolicySubjectView(['slug' => 'rht', 'parent_id' => 0]), 'view');
        self::assertTrue($denied->isForbidden());

        $mutation = $handler->checkProtectedEntityRead(
            $principal,
            $structure,
            new CompiledPolicySubjectView(['slug' => 'rht', 'parent_id' => 0]),
            'update',
        );
        self::assertTrue($mutation->isNeutral(), 'A protected-read classification must not alter mutation authority.');
    }

    #[Test]
    public function duplicate_application_classification_inputs_are_rejected(): void
    {
        $policy = new ClassifiedPolicyProvider(new class implements ClassifiedProtectedEntityReadPolicyInterface {
            public function classificationInputs(): array
            {
                return ['slug', 'slug'];
            }

            public function access(
                \Waaseyaa\Access\AuthorizationPrincipalInterface $principal,
                EntityStructure $structure,
                PolicySubjectViewInterface $subject,
                string $operation,
            ): AccessResult {
                return AccessResult::neutral();
            }
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('duplicate');
        new EntityAccessHandler([$policy])->protectedEntityReadProjectionPlan('page', 'page');
    }

    #[Test]
    public function duplicate_application_classification_inputs_are_rejected_during_hydrated_detail(): void
    {
        $policy = new ClassifiedPolicyProvider(new class implements ClassifiedProtectedEntityReadPolicyInterface {
            public function classificationInputs(): array
            {
                return ['slug', 'slug'];
            }

            public function access(
                \Waaseyaa\Access\AuthorizationPrincipalInterface $principal,
                EntityStructure $structure,
                PolicySubjectViewInterface $subject,
                string $operation,
            ): AccessResult {
                return AccessResult::allowed();
            }
        });
        $handler = new EntityAccessHandler([$policy]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('duplicate');
        $handler->checkProtectedEntityRead(
            new AuthorizationPrincipal(7, true, ['editor'], [], 'test'),
            new EntityStructure('page', 'page', 1),
            new CompiledPolicySubjectView(['slug' => 'rht']),
            'view',
        );
    }

    #[Test]
    public function associative_application_classification_inputs_are_rejected(): void
    {
        $policy = new ClassifiedPolicyProvider(new class implements ClassifiedProtectedEntityReadPolicyInterface {
            public function classificationInputs(): array
            {
                return ['unexpected-key' => 'slug'];
            }

            public function access(
                \Waaseyaa\Access\AuthorizationPrincipalInterface $principal,
                EntityStructure $structure,
                PolicySubjectViewInterface $subject,
                string $operation,
            ): AccessResult {
                return AccessResult::allowed();
            }
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('list');
        new EntityAccessHandler([$policy])->protectedEntityReadProjectionPlan('page', 'page');
    }

    #[Test]
    public function bundle_scoped_classification_is_detected_without_an_exact_query_bundle(): void
    {
        $handler = new EntityAccessHandler([new BundleScopedClassifiedPolicyProvider()]);

        self::assertTrue($handler->hasClassifiedProtectedEntityReadPolicy('page', null));
        self::assertTrue($handler->hasClassifiedProtectedEntityReadPolicy('page', 'article'));
        self::assertFalse($handler->hasClassifiedProtectedEntityReadPolicy('page', 'news'));
    }

    #[Test]
    public function representative_parent_classification_is_bounded_cycle_safe_and_fails_closed(): void
    {
        $policy = new BoundedParentPageReadPolicy([
            10 => ['slug' => 'rht', 'parent_id' => 0],
            20 => ['slug' => 'branch-a', 'parent_id' => 21],
            21 => ['slug' => 'branch-b', 'parent_id' => 20],
        ], maxDepth: 3);
        $plan = new EntityAccessHandler([new ClassifiedPolicyProvider($policy)])
            ->protectedEntityReadProjectionPlan('page', 'page');
        self::assertNotNull($plan);
        $structure = new EntityStructure('page', 'page', 1);
        $nonMember = new AuthorizationPrincipal(7, true, ['editor'], [], 'non-member');
        $member = new AuthorizationPrincipal(8, true, ['member'], ['view members-only pages'], 'member');

        $descendant = new CompiledPolicySubjectView(['slug' => 'child', 'parent_id' => 10]);
        self::assertTrue($plan->access($nonMember, $structure, $descendant, 'view')->isForbidden());
        self::assertTrue($plan->access($member, $structure, $descendant, 'view')->isAllowed());

        $cycle = new CompiledPolicySubjectView(['slug' => 'cycle-child', 'parent_id' => 20]);
        self::assertTrue($plan->access($member, $structure, $cycle, 'view')->isForbidden());
        self::assertLessThanOrEqual(3, $policy->lastTraversalCount);

        $missing = new CompiledPolicySubjectView(['slug' => 'orphan', 'parent_id' => 999]);
        self::assertTrue($plan->access($member, $structure, $missing, 'view')->isForbidden());
    }
}

final class ClassifiedPolicyProvider implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    public function __construct(private ClassifiedProtectedEntityReadPolicyInterface $readPolicy) {}

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'page';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function protectedEntityReadPolicy(): ClassifiedProtectedEntityReadPolicyInterface
    {
        return $this->readPolicy;
    }

    public function protectedFieldReadPolicy(): ?\Waaseyaa\Access\ProtectedFieldReadPolicyInterface
    {
        return null;
    }
}

final class ApplicationPageReadPolicy implements ClassifiedProtectedEntityReadPolicyInterface
{
    public function classificationInputs(): array
    {
        return ['slug', 'parent_id'];
    }

    public function access(
        \Waaseyaa\Access\AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        if ($subject->fields() !== ['parent_id', 'slug']) {
            return AccessResult::forbidden('Complete page classification inputs are required.');
        }

        return $subject->get('slug') === 'rht'
            ? AccessResult::forbidden('Members-only page denied in this test policy.')
            : AccessResult::neutral();
    }
}

#[AccessPolicyAttribute(id: 'classified_article', entityTypes: ['page'], bundles: ['article'])]
final class BundleScopedClassifiedPolicyProvider implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'page';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function protectedEntityReadPolicy(): ClassifiedProtectedEntityReadPolicyInterface
    {
        return new ApplicationPageReadPolicy();
    }

    public function protectedFieldReadPolicy(): ?\Waaseyaa\Access\ProtectedFieldReadPolicyInterface
    {
        return null;
    }
}

final class BoundedParentPageReadPolicy implements ClassifiedProtectedEntityReadPolicyInterface
{
    public int $lastTraversalCount = 0;

    /** @param array<int, array{slug: string, parent_id: int}> $parents */
    public function __construct(
        private readonly array $parents,
        private readonly int $maxDepth,
    ) {}

    public function classificationInputs(): array
    {
        return ['slug', 'parent_id'];
    }

    public function access(
        \Waaseyaa\Access\AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        $this->lastTraversalCount = 0;
        if ($operation !== 'view') {
            return AccessResult::neutral();
        }

        $slug = $subject->get('slug');
        $parentId = $subject->get('parent_id');
        $seen = [];
        while (true) {
            if ($slug === 'rht') {
                return $principal->hasPermission('view members-only pages')
                    ? AccessResult::allowed()
                    : AccessResult::forbidden('The rht branch is members-only.');
            }
            if ($parentId === 0 || $parentId === null) {
                return AccessResult::neutral();
            }
            if (!is_int($parentId)
                || isset($seen[$parentId])
                || $this->lastTraversalCount >= $this->maxDepth
                || !isset($this->parents[$parentId])
            ) {
                return AccessResult::forbidden('Parent classification context is incomplete or cyclic.');
            }

            $seen[$parentId] = true;
            ++$this->lastTraversalCount;
            $slug = $this->parents[$parentId]['slug'];
            $parentId = $this->parents[$parentId]['parent_id'];
        }
    }
}
