<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;

/**
 * Access policy for saved Wayfinding trails (Phase 4, LD-5).
 *
 * Entity-level (deny-by-default):
 *   - view   : the owner, or any holder of the "present guided content"
 *              capability, may view a saved trail.
 *   - update : **owner only** — a trail becomes human-owned on save, and only
 *              its owner edits it (the no-silent-overwrite guarantee is enforced
 *              in {@see \Waaseyaa\Wayfinding\Trail\TrailStore}; this policy keeps
 *              non-owners off the human-edit path entirely).
 *   - delete : owner only.
 *   - create : holders of the "present guided content" capability (the write
 *              tier, LD-2/FR-003).
 *
 * Field-level (open-by-default, Forbidden restricts): `owner_uid` and `origin`
 * are store-managed provenance fields and are never directly editable; identity
 * fields are read-only too.
 *
 * @api
 */
#[PolicyAttribute(entityType: 'wayfinding_trail')]
final class TrailAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    private const string CAPABILITY = EmitBeaconController::CAPABILITY;

    /** Store-managed or identity fields that are never directly editable. */
    private const array READONLY_FIELDS = ['tid', 'uuid', 'revision_id', 'langcode', 'default_langcode', 'owner_uid', 'origin'];

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'wayfinding_trail';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return match ($operation) {
            'view' => $this->viewAccess($entity, $account),
            'update' => $this->ownerOnly($entity, $account, 'update'),
            'delete' => $this->ownerOnly($entity, $account, 'delete'),
            default => AccessResult::neutral("No opinion on '$operation' operation."),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission(self::CAPABILITY)) {
            return AccessResult::allowed('Holder of the "present guided content" capability may create trails.');
        }

        return AccessResult::neutral('Account lacks the "present guided content" capability.');
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($operation === 'edit' && in_array($fieldName, self::READONLY_FIELDS, true)) {
            return AccessResult::forbidden("Field '$fieldName' is store-managed and not directly editable.");
        }

        return AccessResult::neutral();
    }

    private function viewAccess(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        if ($this->isOwner($entity, $account) || $account->hasPermission(self::CAPABILITY)) {
            return AccessResult::allowed('Owner or capability holder may view this trail.');
        }

        return AccessResult::neutral('Account is neither the owner nor a capability holder.');
    }

    private function ownerOnly(EntityInterface $entity, AccountInterface $account, string $operation): AccessResult
    {
        return $this->isOwner($entity, $account)
            ? AccessResult::allowed("Trail owner may {$operation} this trail.")
            : AccessResult::forbidden("Only the trail owner may {$operation} this trail.");
    }

    private function isOwner(EntityInterface $entity, AccountInterface $account): bool
    {
        $ownerUid = $entity->get('owner_uid');
        if ($ownerUid === null) {
            return false;
        }

        return (string) $ownerUid === (string) $account->id() && (int) $account->id() > 0;
    }
}
