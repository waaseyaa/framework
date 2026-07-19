<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Access;

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
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;

/**
 * Initiator-owns-row access policy for `agent_run` and `agent_audit_log`.
 *
 * Rules (entity-level):
 *   - View / update / delete is allowed when the requesting account is the
 *     initiator (`AgentRun.account_id === AccountInterface::id()`) OR holds
 *     the `agent.run.bypass_ownership` permission.
 *   - Otherwise the policy returns `Neutral` — no other v1 policy is
 *     expected to override this, so neutral is effectively denied at the
 *     handler level (`isAllowed()` semantics).
 *   - Create access is gated on the `agent.run` permission.
 *
 * For `agent_audit_log` rows, the parent run is resolved through
 * {@see AgentRunRepository::find()} and the same ownership check is
 * applied. The repository is nullable to allow short-circuit registration
 * in environments that haven't booted the repository yet (kernel-boot
 * attribute scan tolerates this).
 *
 * Field-level access is open-by-default — every field returns
 * `Neutral` so the field-access handler treats it as accessible. Field
 * redaction (e.g. for transcript secrets) is a future extension.
 *
 * @api
 */
#[PolicyAttribute(entityType: ['agent_run', 'agent_audit_log'])]
final class AgentRunAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    public const PERMISSION_RUN = 'agent.run';
    public const PERMISSION_BYPASS_OWNERSHIP = 'agent.run.bypass_ownership';

    /** @var \Closure(EntityBase): PolicySubjectViewInterface */
    private readonly \Closure $policySubjectAuthority;

    public function __construct(
        private readonly ?AgentRunRepository $runRepository = null,
    ) {
        $this->policySubjectAuthority = \Closure::bind(
            static fn(EntityBase $entity): PolicySubjectViewInterface => $entity->valueContainer->entityPolicySubjectView(),
            null,
            EntityBase::class,
        );
    }

    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface
    {
        return new AgentRunOwnerEntityReadPolicy($this->runRepository);
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new AgentRunOwnerFieldReadPolicy();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'agent_run' || $entityTypeId === 'agent_audit_log';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $run = $this->resolveRun($entity);
        if ($run === null) {
            return AccessResult::neutral('parent run not resolvable');
        }

        if ($account->hasPermission(self::PERMISSION_BYPASS_OWNERSHIP)) {
            return AccessResult::allowed('bypass-ownership permission holder');
        }

        if ($this->isOwner($run, $account)) {
            return AccessResult::allowed('initiator owns row');
        }

        return AccessResult::neutral('non-initiator without bypass');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (!$this->appliesTo($entityTypeId)) {
            return AccessResult::neutral();
        }

        if ($account->hasPermission(self::PERMISSION_RUN)) {
            return AccessResult::allowed('agent.run permission holder');
        }

        return AccessResult::neutral('agent.run permission required');
    }

    /**
     * Field access is open-by-default — Neutral here means "accessible",
     * because `EntityAccessHandler` uses `!isForbidden()` semantics at the
     * field level (constitution: open-by-default).
     */
    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        return AccessResult::neutral();
    }

    /**
     * Resolve the parent `AgentRun` for the access check. `AgentRun`
     * resolves to itself; `AgentAuditLog` resolves via the run repository.
     */
    private function resolveRun(EntityInterface $entity): ?AgentRun
    {
        if ($entity instanceof AgentRun) {
            return $entity;
        }

        if ($entity instanceof AgentAuditLog && $this->runRepository !== null) {
            $subject = ($this->policySubjectAuthority)($entity);
            if ($subject->fields() !== ['run_id']) {
                return null;
            }
            $runId = (string) $subject->get('run_id');
            return $runId !== '' ? $this->runRepository->find($runId) : null;
        }

        return null;
    }

    private function isOwner(AgentRun $run, AccountInterface $account): bool
    {
        $subject = ($this->policySubjectAuthority)($run);
        if ($subject->fields() !== ['account_id']) {
            return false;
        }
        $runAccountId = $subject->get('account_id');
        $callerId = $account->id();

        if (\is_int($callerId)) {
            return $callerId === $runAccountId;
        }

        return $callerId === (string) $runAccountId;
    }
}

/** V2 immutable owner policy for protected AgentRun reads. @api */
final class AgentRunOwnerEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    /** @var \Closure(EntityBase): PolicySubjectViewInterface */
    private readonly \Closure $policySubjectAuthority;

    public function __construct(private readonly ?AgentRunRepository $runRepository = null)
    {
        $this->policySubjectAuthority = \Closure::bind(
            static fn(EntityBase $entity): PolicySubjectViewInterface => $entity->valueContainer->entityPolicySubjectView(),
            null,
            EntityBase::class,
        );
    }

    public function access(AuthorizationPrincipalInterface $principal, EntityStructure $structure, PolicySubjectViewInterface $subject, string $operation): AccessResult
    {
        if ($structure->entityTypeId === 'agent_audit_log' && $subject->fields() === ['run_id'] && $this->runRepository !== null) {
            $run = $this->runRepository->find((string) $subject->get('run_id'));
            if ($run === null) {
                return AccessResult::forbidden('Agent audit-log parent run is unavailable.');
            }
            $runSubject = ($this->policySubjectAuthority)($run);

            return $runSubject->fields() === ['account_id']
                ? self::ownerResult($principal, $runSubject)
                : AccessResult::forbidden('AgentRun access requires the exact compiled owner input.');
        }

        if ($structure->entityTypeId !== 'agent_run' || $subject->fields() !== ['account_id']) {
            return AccessResult::forbidden('AgentRun access requires the exact compiled owner input.');
        }

        return self::ownerResult($principal, $subject);
    }

    public static function ownerResult(AuthorizationPrincipalInterface $principal, PolicySubjectViewInterface $subject): AccessResult
    {
        if ($principal->hasPermission(AgentRunAccessPolicy::PERMISSION_BYPASS_OWNERSHIP)) {
            return AccessResult::allowed('bypass-ownership permission holder');
        }

        return (string) $principal->id() === (string) $subject->get('account_id')
            ? AccessResult::allowed('initiator owns row')
            : AccessResult::forbidden('non-initiator without bypass');
    }
}

/** V2 immutable owner policy for protected AgentRun fields. @api */
final class AgentRunOwnerFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function access(AuthorizationPrincipalInterface $principal, EntityStructure $structure, PolicySubjectViewInterface $subject, string $fieldName): AccessResult
    {
        if ($structure->entityTypeId !== 'agent_run' || $fieldName === 'account_id' || $subject->fields() !== ['account_id']) {
            return AccessResult::forbidden('AgentRun field access requires the exact compiled owner input.');
        }

        return AgentRunOwnerEntityReadPolicy::ownerResult($principal, $subject);
    }
}
