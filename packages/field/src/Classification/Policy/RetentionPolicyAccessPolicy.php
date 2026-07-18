<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Field\Classification\Permissions;

/** Governance-role access to retention-policy configuration. @api */
#[PolicyAttribute(entityType: 'retention_policy')]
final class RetentionPolicyAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'retention_policy';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return $this->decision($account, $operation);
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return $this->decision($account, 'create');
    }

    public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
    {
        return $this->decision($account, $operation === 'view' ? 'view' : 'update');
    }

    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface
    {
        return new RetentionProtectedEntityReadPolicy($this);
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new RetentionProtectedFieldReadPolicy($this);
    }

    /** @internal */
    public function protectedDecision(AuthorizationPrincipalInterface $principal, string $operation): AccessResult
    {
        return $this->decision($principal, $operation);
    }

    private function decision(AccountInterface $account, string $operation): AccessResult
    {
        $roles = $account->getRoles();
        $mayView = in_array('admin', $roles, true)
            || in_array('administrator', $roles, true)
            || in_array(Permissions::ROLE_GOVERNANCE_VIEWER, $roles, true);
        if ($operation === 'view') {
            return $mayView
                ? AccessResult::allowed('Governance role may view retention policy configuration.')
                : AccessResult::forbidden('Retention policy configuration requires a governance role.');
        }

        return in_array('admin', $roles, true) || in_array('administrator', $roles, true)
            ? AccessResult::allowed('Administrator may mutate retention policy configuration.')
            : AccessResult::forbidden('Only administrators may mutate retention policy configuration.');
    }
}

/** Immutable-principal retention-policy entity decision. @api */
final readonly class RetentionProtectedEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function __construct(private RetentionPolicyAccessPolicy $policy) {}

    public function access(AuthorizationPrincipalInterface $principal, EntityStructure $structure, PolicySubjectViewInterface $subject, string $operation): AccessResult
    {
        return $this->policy->protectedDecision($principal, $operation);
    }
}

/** Governance-role release of Protected retention-policy fields. @api */
final readonly class RetentionProtectedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function __construct(private RetentionPolicyAccessPolicy $policy) {}

    public function access(AuthorizationPrincipalInterface $principal, EntityStructure $structure, PolicySubjectViewInterface $subject, string $fieldName): AccessResult
    {
        return $this->policy->protectedDecision($principal, 'view');
    }
}
