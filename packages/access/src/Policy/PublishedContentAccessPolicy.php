<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Framework-default access policy: published content is publicly viewable.
 *
 * This makes a freshly-created, published content entity anonymously readable
 * via HTML, Markdown negotiation, and MCP `entity.read` with NO hand-written
 * per-type policy — the author-path remediation goal.
 *
 * It is strictly **additive** and security-bounded:
 *   - It returns `Allowed` ONLY for the `view` operation on a PUBLISHED entity
 *     whose entity type is in the `content` group; otherwise `Neutral`.
 *   - It NEVER returns `Forbidden`, so a more specific policy's `Forbidden`
 *     still wins (the handler composes via `orIf`, Forbidden-wins).
 *   - It scopes to the `content` group, so non-content types (e.g. `user`
 *     whose `status` means *active*, `taxonomy`) are never auto-exposed.
 *   - Unpublished content (and content with no published signal) gets `Neutral`
 *     → denied unless another policy grants it.
 *
 * Field-level exposure remains governed by `FieldAccessPolicyInterface` /
 * internal-field filtering at the serializers — this grants entity-level view,
 * not field bypass.
 *
 * @api
 */
final class PublishedContentAccessPolicy implements AccessPolicyInterface
{
    /** Entity-type group whose published members are public by default. */
    public const string PUBLIC_GROUP = 'content';

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function appliesTo(string $entityTypeId): bool
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return false;
        }

        return $this->entityTypeManager->getDefinition($entityTypeId)->getGroup() === self::PUBLIC_GROUP;
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view' && $this->isPublished($entity)) {
            return AccessResult::allowed('Published content is publicly viewable by default.');
        }

        // Additive only — never Forbidden, never an opinion on write operations.
        return AccessResult::neutral('Default policy grants only published-content view.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Default policy has no opinion on create.');
    }

    /**
     * Published = a truthy `status` field. Content types created by
     * `make:content-type` (and `node`) carry `status`; a content entity without
     * a published signal is treated as NOT published (denied).
     */
    private function isPublished(EntityInterface $entity): bool
    {
        try {
            $status = $entity->get('status');
        } catch (\Throwable) {
            return false;
        }

        return $status === true || $status === 1 || $status === '1';
    }
}
