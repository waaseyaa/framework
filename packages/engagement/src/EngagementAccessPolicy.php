<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[PolicyAttribute(entityType: ['reaction', 'comment', 'follow'])]
final class EngagementAccessPolicy implements AccessPolicyInterface
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

        return $userId !== null && (int) $userId === (int) $account->id();
    }

    private function ownerCheck(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        if ($this->isOwner($entity, $account)) {
            return AccessResult::allowed('Owner may delete own engagement entity.');
        }

        return AccessResult::neutral('Non-owner cannot delete engagement entity.');
    }
}
