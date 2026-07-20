<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Workflows\Transition\TransitionService;

/**
 * Additive node-view grant derived from exact current workflow authority.
 *
 * Field access and every mutation operation deliberately remain outside this
 * policy. Existing node policies continue to decide them.
 */
#[PolicyAttribute(entityType: 'node')]
final class WorkflowAuthorityAccessPolicy implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    private readonly WorkflowAuthorityVisibility $visibility;

    private readonly WorkflowAuthorityProtectedReadPolicy $protectedEntityPolicy;

    public function __construct(
        TransitionService $transitionService,
        EntityTypeManagerInterface $entityTypeManager,
        ?LoggerInterface $logger = null,
    ) {
        $this->visibility = new WorkflowAuthorityVisibility($transitionService, $entityTypeManager, $logger);
        $this->protectedEntityPolicy = new WorkflowAuthorityProtectedReadPolicy($this->visibility);
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral('Workflow authority grants entity view only.');
        }

        return $this->visibility->allows(
            $entity->getEntityTypeId(),
            $entity->bundle(),
            $entity->id(),
            $account,
        )
            ? AccessResult::allowed('A current outgoing workflow transition grants working-copy visibility.')
            : AccessResult::neutral('No authorized current outgoing workflow transition grants visibility.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Workflow authority does not grant create access.');
    }

    public function protectedEntityReadPolicy(): WorkflowAuthorityProtectedReadPolicy
    {
        return $this->protectedEntityPolicy;
    }

    public function protectedFieldReadPolicy(): ?ProtectedFieldReadPolicyInterface
    {
        return null;
    }
}
