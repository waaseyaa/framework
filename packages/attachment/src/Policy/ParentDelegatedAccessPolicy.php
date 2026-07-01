<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Access policy for Attachment entities that delegates decisions to the parent entity.
 *
 * View, update, and delete access on an attachment is granted if and only if
 * the same operation is granted on the parent entity. This enforces the
 * attachment access model: attachments are as accessible as their parent.
 *
 * Returns Neutral when:
 * - The entity is not an Attachment (defensive).
 * - The parent entity type or ID is empty (orphaned/incomplete data).
 * - The parent entity cannot be loaded (referential integrity gap).
 *
 * Per the access-result semantics in CLAUDE.md, entity-level uses isAllowed()
 * (deny unless granted), so Neutral on a missing parent effectively denies
 * access without encoding an explicit Forbidden decision.
 *
 * Auto-discovered at kernel boot via the #[PolicyAttribute] attribute.
 * See CLAUDE.md § "discoverAccessPolicies() constructor heuristic".
 *
 * Spec: FR-011.
 */
#[PolicyAttribute(entityType: 'attachment')]
final class ParentDelegatedAccessPolicy implements AccessPolicyInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
    ) {}

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (!$entity instanceof Attachment) {
            // Defensive: this policy only handles Attachment entities.
            return AccessResult::neutral('Not an Attachment entity.');
        }

        $parentType = (string) $entity->get('parent_entity_type');
        $parentId = (string) $entity->get('parent_entity_id');

        if ($parentType === '' || $parentId === '') {
            // Incomplete attachment — no parent reference to delegate to.
            return AccessResult::neutral('Attachment has no parent entity reference.');
        }

        // C-22 WP3: read path now goes through the canonical repository.
        $parent = $this->entityTypeManager->getRepository($parentType)->find($parentId);

        if ($parent === null) {
            // Referential integrity gap: parent entity no longer exists.
            // Return Neutral (not Forbidden) — isAllowed() denies by default.
            return AccessResult::neutral('Parent entity not found.');
        }

        // Delegate the access decision to the parent entity's registered policy.
        return $this->accessHandler->check($parent, $operation, $account);
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        // Create access for attachments is not delegated via this policy.
        // It is governed by the parent entity's create access at the API layer.
        return AccessResult::neutral('Create access is not governed by parent delegation.');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'attachment';
    }
}
