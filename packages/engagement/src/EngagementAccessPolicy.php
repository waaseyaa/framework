<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Access policy for reaction/comment/follow entities.
 *
 * Entity-level: cascades view access from the parent content the engagement
 * targets, withholds unpublished comments from non-owners, and lets the
 * owner delete their own row.
 *
 * Field-level (open-by-default, Forbidden restricts): `user_id` is the
 * ownership key that `isOwner()` trusts for the delete/unpublished-view
 * checks above, so it must be server-authoritative and never
 * client-reassignable. Without this gate, the JSON:API create path (which
 * runs `checkFieldAccess('edit')` open-by-default) lets any authenticated
 * account mint a row with an arbitrary `user_id` — including `0`, the
 * anonymous account's id — because `EngagementAccessPolicy` declared no
 * `FieldAccessPolicyInterface` at all. This is the same class of hole fixed
 * in `NodeAccessPolicy` (`uid`/`type`/`created`/`changed` admin-only-edit
 * fields), except stricter: node's `uid` is admin-editable and
 * author-settable-on-create; engagement's `user_id` is immutable outright
 * once the row exists (ownership never transfers) and, on create, settable
 * only to the caller's own id (the entity constructors already require
 * `user_id`, so a legitimate client always submits its own).
 *
 * This complements a companion fix in `isOwner()`: `AnonymousUser::id()`
 * returns `0`, so a row with `user_id === 0` (previously mintable via the
 * field hole above) made every anonymous visitor its "owner" absent an
 * `isAuthenticated()` guard — granting anonymous DELETE and anonymous view
 * of unpublished comments. `isOwner()` now requires `isAuthenticated()`,
 * mirroring `NodeAccessPolicy::access()`'s `$isOwner` guard.
 */
#[PolicyAttribute(entityType: ['reaction', 'comment', 'follow'])]
final class EngagementAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    /** @var list<string> */
    private const TYPES = ['reaction', 'comment', 'follow'];

    /**
     * The parent-cascade and comment-moderation checks need to load the parent
     * content and ask the access system whether the caller may view it. The
     * kernel's two-phase policy discovery injects these (the EntityAccessHandler
     * via the preliminary phase-1 handler); they are nullable so the policy is
     * still constructible standalone, in which case view access fails closed
     * because parent visibility cannot be proven.
     */
    public function __construct(
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
        private readonly ?EntityAccessHandler $accessHandler = null,
    ) {}

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
            'view' => $this->viewAccess($entity, $account),
            'delete' => $this->ownerCheck($entity, $account),
            default => AccessResult::neutral('Non-admin cannot modify engagement entities.'),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        if ($account->isAuthenticated()) {
            return AccessResult::allowed('Authenticated users may create engagement entities.');
        }

        return AccessResult::neutral('Anonymous users cannot create engagement entities.');
    }

    /**
     * An engagement entity is only as visible as the content it is attached to
     * (parent-cascade), and an unpublished/unmoderated comment is visible only
     * to its owner. Anything we cannot positively confirm as viewable is
     * denied (Neutral), so the default is fail-closed.
     */
    private function viewAccess(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        // Comment moderation: a non-published comment is withheld from everyone
        // but its owner (admins are already handled above).
        if ($entity->getEntityTypeId() === 'comment' && !$this->isPublished($entity)) {
            if ($this->isOwner($entity, $account)) {
                return AccessResult::allowed('Owner may view own unpublished comment.');
            }

            return AccessResult::neutral('Unpublished comment is hidden from non-owners.');
        }

        return $this->parentViewAccess($entity, $account);
    }

    /**
     * Cascade visibility from the parent content: the caller may view the
     * engagement only if they may view the entity it targets.
     */
    private function parentViewAccess(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        if ($this->entityTypeManager === null || $this->accessHandler === null) {
            return AccessResult::neutral('Parent visibility cannot be evaluated.');
        }

        $targetType = (string) ($entity->get('target_type') ?? '');
        $targetId = (int) ($entity->get('target_id') ?? 0);

        if ($targetType === '' || $targetId <= 0 || !$this->entityTypeManager->hasDefinition($targetType)) {
            return AccessResult::neutral('Engagement has no resolvable parent.');
        }

        // C-22 WP3: read path now goes through the canonical repository.
        $parent = $this->entityTypeManager->getRepository($targetType)->find((string) $targetId);
        if ($parent === null) {
            return AccessResult::neutral('Parent content not found.');
        }

        return $this->accessHandler->check($parent, 'view', $account)->isAllowed()
            ? AccessResult::allowed('Parent content is viewable by the caller.')
            : AccessResult::neutral('Parent content is not viewable by the caller.');
    }

    private function isPublished(EntityInterface $entity): bool
    {
        $status = $entity->get('status');

        if (is_bool($status)) {
            return $status;
        }
        if (is_numeric($status)) {
            return (int) $status === 1;
        }
        if (is_string($status)) {
            return in_array(strtolower(trim($status)), ['1', 'true', 'published', 'yes'], true);
        }

        // Unknown/absent status on a comment → treat as unpublished (fail-closed).
        return false;
    }

    private function isOwner(EntityInterface $entity, AccountInterface $account): bool
    {
        $userId = $entity->get('user_id');

        // An unauthenticated account is never an owner: the anonymous
        // account's id() is 0, which would otherwise equal a row's
        // user_id === 0 (e.g. an unowned/legacy row, or one minted via the
        // user_id-mass-assignment hole fieldAccess() below now closes),
        // making anonymous the "owner" of every such row and granting it
        // DELETE plus view of unpublished comments. Mirrors
        // NodeAccessPolicy::access()'s $isOwner guard.
        return $account->isAuthenticated() && $userId !== null && (int) $userId === (int) $account->id();
    }

    /**
     * Field-level gate: `user_id` is server-authoritative and never
     * client-reassignable. See the class docblock for the full rationale.
     */
    public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
    {
        // This gate restricts writes only; view of these fields is unaffected.
        if ($operation !== 'edit') {
            return AccessResult::neutral('Engagement field gate restricts edit only.');
        }

        if ($account->hasPermission('administer content')) {
            return AccessResult::neutral('Admin may edit any engagement field.');
        }

        if ($fieldName === 'user_id') {
            // Ownership is immutable after creation — never reassignable,
            // even by the current owner.
            if (!$entity->isNew()) {
                return AccessResult::forbidden('user_id is immutable on an existing engagement entity.');
            }

            // At CREATE, a non-admin may only mint a row owned by themselves.
            // This is what actually closes the anonymous-ownership hole: it
            // rejects user_id: 0 (anonymous) and user_id: <other account>
            // regardless of who the authenticated caller is, while still
            // allowing the only legitimate case (self-owned creation).
            if ((int) $entity->get('user_id') !== (int) $account->id()) {
                return AccessResult::forbidden('A non-admin may only create engagement entities owned by themselves (user_id must equal your own account id).');
            }
        }

        return AccessResult::neutral("No field-edit opinion on '$fieldName'.");
    }

    private function ownerCheck(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        if ($this->isOwner($entity, $account)) {
            return AccessResult::allowed('Owner may delete own engagement entity.');
        }

        return AccessResult::neutral('Non-owner cannot delete engagement entity.');
    }
}
