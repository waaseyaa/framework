<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
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
