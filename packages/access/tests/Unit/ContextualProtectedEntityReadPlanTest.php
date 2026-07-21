<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\CompiledPolicySubjectView;
use Waaseyaa\Access\ContextualProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ContextualProtectedReadCandidate;
use Waaseyaa\Access\ContextualProtectedReadEvaluation;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProjectedProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;

final class ContextualProtectedEntityReadPlanTest extends TestCase
{
    #[Test]
    public function it_composes_a_capability_batch_with_the_existing_status_policy_and_fails_closed(): void
    {
        $boundary = new \stdClass();
        $handler = new EntityAccessHandler([
            new ContextualProvider(new StatusPolicy()),
            new ContextualProvider(new CapabilityBatchPolicy($boundary, 'manage roster')),
        ]);
        $plan = $handler->contextualProtectedEntityReadPlan('user', 'user');

        self::assertNotNull($plan);
        self::assertSame($boundary, $plan->authorizationBoundary);
        self::assertNull($handler->protectedEntityReadProjectionPlan('user', 'user'));

        $active = new ContextualProtectedReadCandidate(
            '7',
            new EntityStructure('user', 'user', 7),
            new CompiledPolicySubjectView(['status' => true]),
        );
        $inactive = new ContextualProtectedReadCandidate(
            '8',
            new EntityStructure('user', 'user', 8),
            new CompiledPolicySubjectView(['status' => false]),
        );
        $clerk = new AuthorizationPrincipal(1, true, [], ['manage roster'], 'g1');
        $director = new AuthorizationPrincipal(2, true, [], ['view roster'], 'g1');
        $evaluation = $plan->beginEvaluation($boundary, 1234);

        $clerkDecisions = $plan->accessBatch($clerk, [$active, $inactive], $evaluation);
        self::assertTrue($clerkDecisions['7']->isAllowed());
        self::assertTrue($clerkDecisions['8']->isForbidden());

        $directorDecisions = $plan->accessBatch($director, [$active], $evaluation);
        self::assertTrue($directorDecisions['7']->isNeutral());
        $requiredDirectorDecisions = $plan->accessBatch(
            $director,
            [$active],
            $evaluation,
            requiredContextKey: 'test-capability',
        );
        self::assertTrue($requiredDirectorDecisions['7']->isForbidden());

        $plan->closeEvaluation($evaluation);
        self::assertTrue($plan->accessBatch($clerk, [$active], $evaluation)['7']->isForbidden());
    }

    #[Test]
    public function evaluation_context_aliases_share_revocation_and_cannot_be_serialized_or_replayed(): void
    {
        $boundary = new \stdClass();
        $plan = (new EntityAccessHandler([
            new ContextualProvider(new CapabilityBatchPolicy($boundary, 'manage roster')),
        ]))->contextualProtectedEntityReadPlan('user', 'user');
        self::assertNotNull($plan);
        $evaluation = $plan->beginEvaluation($boundary, 1234);

        $alias = clone $evaluation;
        try {
            serialize($evaluation);
            self::fail('A contextual evaluation must not be serializable.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }

        $candidate = new ContextualProtectedReadCandidate(
            '7',
            new EntityStructure('user', 'user', 7),
            new CompiledPolicySubjectView([]),
        );
        $plan->closeEvaluation($evaluation);
        foreach ([$evaluation, $alias] as $closedEvaluation) {
            self::assertTrue($plan->accessBatch(
                new AuthorizationPrincipal(1, true, [], ['manage roster'], 'g1'),
                [$candidate],
                $closedEvaluation,
            )['7']->isForbidden());
        }
    }

    #[Test]
    public function malformed_contextual_output_denies_every_candidate(): void
    {
        $boundary = new \stdClass();
        $handler = new EntityAccessHandler([
            new ContextualProvider(new MalformedBatchPolicy($boundary, 'manage roster')),
        ]);
        $plan = $handler->contextualProtectedEntityReadPlan('user', 'user');
        self::assertNotNull($plan);
        $candidate = new ContextualProtectedReadCandidate(
            '7',
            new EntityStructure('user', 'user', 7),
            new CompiledPolicySubjectView([]),
        );

        $decisions = $plan->accessBatch(
            new AuthorizationPrincipal(1, true, [], ['manage roster'], 'g1'),
            [$candidate],
            $plan->beginEvaluation($boundary, 1234),
        );

        self::assertTrue($decisions['7']->isForbidden());
    }

    #[Test]
    public function extra_reordered_invalid_or_duplicate_batch_identities_deny_the_complete_batch(): void
    {
        $boundary = new \stdClass();
        $candidates = [
            new ContextualProtectedReadCandidate(
                '7',
                new EntityStructure('user', 'user', 7),
                new CompiledPolicySubjectView([]),
            ),
            new ContextualProtectedReadCandidate(
                '8',
                new EntityStructure('user', 'user', 8),
                new CompiledPolicySubjectView([]),
            ),
        ];
        $principal = new AuthorizationPrincipal(1, true, [], ['manage roster'], 'g1');

        foreach ([
            new ExtraBatchPolicy($boundary, 'manage roster'),
            new ReorderedBatchPolicy($boundary, 'manage roster'),
            new InvalidBatchPolicy($boundary, 'manage roster'),
        ] as $policy) {
            $plan = (new EntityAccessHandler([new ContextualProvider($policy)]))
                ->contextualProtectedEntityReadPlan('user', 'user');
            self::assertNotNull($plan);
            $decisions = $plan->accessBatch(
                $principal,
                $candidates,
                $plan->beginEvaluation($boundary, 1234),
            );
            self::assertTrue($decisions['7']->isForbidden());
            self::assertTrue($decisions['8']->isForbidden());
        }

        $plan = (new EntityAccessHandler([
            new ContextualProvider(new CapabilityBatchPolicy($boundary, 'manage roster')),
        ]))->contextualProtectedEntityReadPlan('user', 'user');
        self::assertNotNull($plan);
        $duplicateDecisions = $plan->accessBatch(
            $principal,
            [$candidates[0], $candidates[0]],
            $plan->beginEvaluation($boundary, 1234),
        );
        self::assertTrue($duplicateDecisions['7']->isForbidden());
    }
}

final class ContextualProvider implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    public function __construct(private readonly ProtectedEntityReadPolicyInterface $policy) {}

    public function appliesTo(string $entityTypeId): bool { return $entityTypeId === 'user'; }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface { return $this->policy; }

    public function protectedFieldReadPolicy(): ?ProtectedFieldReadPolicyInterface { return null; }
}

final class StatusPolicy implements ProjectedProtectedEntityReadPolicyInterface
{
    public function authorizationInputs(): array { return ['status']; }

    public function classificationInputs(): array { return ['status']; }

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        return $subject->get('status') === true
            ? AccessResult::neutral()
            : AccessResult::forbidden('Inactive rows remain sealed.');
    }
}

class CapabilityBatchPolicy implements ContextualProtectedEntityReadPolicyInterface
{
    public function __construct(private readonly object $boundary, private readonly string $permission) {}

    public function authorizationBoundary(): object { return $this->boundary; }

    public function contextKey(): string { return 'test-capability'; }

    public function accessBatch(
        AuthorizationPrincipalInterface $principal,
        array $candidates,
        ContextualProtectedReadEvaluation $evaluation,
        string $operation,
    ): array {
        $result = [];
        foreach ($candidates as $candidate) {
            $result[$candidate->key] = $principal->hasPermission($this->permission)
                ? AccessResult::allowed()
                : AccessResult::neutral();
        }

        return $result;
    }

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        return AccessResult::neutral('Contextual policies require batch evaluation.');
    }
}

final class MalformedBatchPolicy extends CapabilityBatchPolicy
{
    public function accessBatch(
        AuthorizationPrincipalInterface $principal,
        array $candidates,
        ContextualProtectedReadEvaluation $evaluation,
        string $operation,
    ): array {
        return [];
    }
}

final class ExtraBatchPolicy extends CapabilityBatchPolicy
{
    public function accessBatch(
        AuthorizationPrincipalInterface $principal,
        array $candidates,
        ContextualProtectedReadEvaluation $evaluation,
        string $operation,
    ): array {
        return ['7' => AccessResult::allowed(), '8' => AccessResult::allowed(), '9' => AccessResult::allowed()];
    }
}

final class ReorderedBatchPolicy extends CapabilityBatchPolicy
{
    public function accessBatch(
        AuthorizationPrincipalInterface $principal,
        array $candidates,
        ContextualProtectedReadEvaluation $evaluation,
        string $operation,
    ): array {
        return ['8' => AccessResult::allowed(), '7' => AccessResult::allowed()];
    }
}

final class InvalidBatchPolicy extends CapabilityBatchPolicy
{
    public function accessBatch(
        AuthorizationPrincipalInterface $principal,
        array $candidates,
        ContextualProtectedReadEvaluation $evaluation,
        string $operation,
    ): array {
        return ['7' => 'allowed', '8' => AccessResult::allowed()];
    }
}
