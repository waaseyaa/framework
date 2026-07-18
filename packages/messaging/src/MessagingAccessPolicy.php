<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging;

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
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Participant-only access for direct messaging.
 *
 * Backs the README/spec guarantee that "only participants can read or post" with
 * actual policy code (previously nothing enforced it):
 *
 * - **Read** ({@see access()}, `view`) — a `message_thread`, `thread_message`, or
 *   `thread_participant` is viewable only by an account that is a participant of
 *   the relevant thread. `JsonApiController::index()/show()` gate reads on
 *   `check(entity, 'view', account)->isAllowed()`, so non-participant rows are
 *   filtered out / 403'd.
 * - **Post / modify** ({@see fieldAccess()}, `edit`) — editing the fields of a
 *   `thread_message`/`thread_participant` (which is how a message is *created*
 *   too: `JsonApiController::store()` runs `checkFieldAccess('edit')` on the
 *   constructed entity, which already carries `thread_id`) is **Forbidden**
 *   unless the acting account is a participant of that thread. This is the only
 *   create-time hook that sees the target thread — `createAccess()` does not.
 *
 * Creating a brand-new `message_thread` is permitted to any authenticated
 * account ({@see createAccess()}): a new thread has no participants yet, and the
 * creator is establishing it. Account holders with `administer content` bypass.
 *
 * The {@see EntityTypeManagerInterface} is injected by the kernel's policy
 * dependency resolver; it is nullable so the policy is constructible standalone,
 * in which case participation cannot be proven and access fails closed.
 */
#[PolicyAttribute(entityType: ['message_thread', 'thread_message', 'thread_participant'])]
final class MessagingAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    /** @var list<string> */
    private const TYPES = ['message_thread', 'thread_message', 'thread_participant'];

    /** @var \Closure(EntityBase): PolicySubjectViewInterface */
    private readonly \Closure $policySubjectAuthority;

    public function __construct(
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
    ) {
        $this->policySubjectAuthority = \Closure::bind(
            static fn(EntityBase $entity): PolicySubjectViewInterface => $entity->valueContainer->entityPolicySubjectView(),
            null,
            EntityBase::class,
        );
    }

    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface
    {
        return new MessagingProtectedEntityReadPolicy($this);
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new MessagingProtectedFieldReadPolicy($this);
    }

    /** @internal Immutable-principal view decision over exact structural and compiled inputs. */
    public function protectedViewAccess(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
    ): AccessResult {
        if ($principal->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        return $this->isParticipant($this->threadIdForSubject($structure, $subject), $principal)
            ? AccessResult::allowed('Thread participant.')
            : AccessResult::forbidden('Not a thread participant.');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return in_array($entityTypeId, self::TYPES, true);
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        return match ($operation) {
            'view', 'update', 'delete' => $this->isParticipant($this->threadIdFor($entity), $account)
                ? AccessResult::allowed('Thread participant.')
                : AccessResult::neutral('Not a thread participant.'),
            default => AccessResult::neutral('No opinion.'),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        // createAccess() cannot see the target thread, so per-thread posting is
        // gated by fieldAccess() on the constructed entity. Here we only block
        // anonymous writes; the participant check happens at field level.
        if ($account->isAuthenticated()) {
            return AccessResult::allowed('Authenticated account.');
        }

        return AccessResult::neutral('Anonymous accounts cannot write messaging entities.');
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        // Reads are gated by access('view'); field-access is open-by-default for
        // view. Only restrict edits (which includes create, per store()).
        if ($operation !== 'edit') {
            return AccessResult::neutral('Field view handled by entity view access.');
        }

        if ($account->hasPermission('administer content')) {
            return AccessResult::neutral('Admin permission.');
        }

        // A brand-new message_thread has no participants yet; its creation is
        // gated by createAccess(), not field-level participation.
        if ($entity->getEntityTypeId() === 'message_thread') {
            return AccessResult::neutral('Thread creation/edit is not field-gated.');
        }

        // thread_message / thread_participant carry a thread_id referencing an
        // existing thread: only a participant of that thread may post/modify.
        $threadId = $this->threadIdFor($entity);
        if ($threadId <= 0) {
            return AccessResult::neutral('No resolvable thread.');
        }

        return $this->isParticipant($threadId, $account)
            ? AccessResult::neutral('Thread participant may post/modify.')
            : AccessResult::forbidden('Only thread participants may post to or modify this thread.');
    }

    private function threadIdFor(EntityInterface $entity): int
    {
        if ($entity->getEntityTypeId() === 'message_thread') {
            return (int) $entity->id();
        }

        if ($entity instanceof EntityBase) {
            return (int) (($this->policySubjectAuthority)($entity)->get('thread_id') ?? 0);
        }

        return (int) ($entity->get('thread_id') ?? 0);
    }

    private function threadIdForSubject(EntityStructure $structure, PolicySubjectViewInterface $subject): int
    {
        return $structure->entityTypeId === 'message_thread'
            ? (int) ($structure->id ?? 0)
            : (int) ($subject->get('thread_id') ?? 0);
    }

    private function isParticipant(int $threadId, AccountInterface $account): bool
    {
        if ($threadId <= 0 || $this->entityTypeManager === null) {
            return false;
        }

        try {
            // System integrity query (the policy IS the access boundary), so it
            // opts out of the per-query account gate via accessCheck(false).
            // C-22 WP2: the query builder now lives on the repository.
            $ids = $this->entityTypeManager->getRepository('thread_participant')->getQuery()
                ->accessCheck(false)
                ->condition('thread_id', $threadId)
                ->condition('user_id', (int) $account->id())
                ->range(0, 1)
                ->execute();

            return $ids !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}

/** Participant-only Protected entity visibility. @api */
final readonly class MessagingProtectedEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function __construct(private MessagingAccessPolicy $policy) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        return $operation === 'view'
            ? $this->policy->protectedViewAccess($principal, $structure, $subject)
            : AccessResult::neutral();
    }
}

/** Releases messaging fields only to a participant of their containing thread. @api */
final readonly class MessagingProtectedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function __construct(private MessagingAccessPolicy $policy) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        return $this->policy->protectedViewAccess($principal, $structure, $subject);
    }
}
