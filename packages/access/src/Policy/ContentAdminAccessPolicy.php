<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Framework-default policy: an account holding `administer content` may fully
 * manage any entity in the `content` group — `view`/`update`/`delete` (including
 * unpublished drafts) and `create` — with NO hand-written per-type policy.
 *
 * This is the generic, per-GROUP analogue of `NodeAccessPolicy`'s
 * `administer nodes` bypass: a type scaffolded by `make:content-type` becomes
 * fully manageable in the admin SPA out of the box, drafts included, without an
 * app author writing an `AccessPolicy`.
 *
 * Strictly additive and security-bounded:
 *   - Scoped to the `content` group (via {@see appliesTo()}), so non-content
 *     types (`user`, `taxonomy`, …) are never granted by this policy.
 *   - Grants ONLY when the account holds `administer content`. Anonymous and
 *     non-admin accounts get `Neutral`, so the public/MCP read path keeps its
 *     published-view-only boundary via {@see PublishedContentAccessPolicy} and
 *     never gains manage or draft visibility here.
 *   - NEVER returns `Forbidden`, so a more specific policy's `Forbidden` still
 *     wins (the handler composes via `orIf`, Forbidden-wins).
 *
 * Field-level exposure remains governed by `FieldAccessPolicyInterface` /
 * internal-field filtering at the serializers — this grants entity-level
 * management, not a field-policy bypass.
 *
 * @api
 */
final class ContentAdminAccessPolicy implements AccessPolicyInterface
{
    /** Entity-type group this policy governs. */
    public const string CONTENT_GROUP = 'content';

    /** Permission that grants full management of content-group entities. */
    public const string ADMIN_PERMISSION = 'administer content';

    /** Operations a content administrator may perform on a loaded entity. */
    private const array MANAGED_OPERATIONS = ['view', 'update', 'delete'];

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function appliesTo(string $entityTypeId): bool
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return false;
        }

        return $this->entityTypeManager->getDefinition($entityTypeId)->getGroup() === self::CONTENT_GROUP;
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (
            in_array($operation, self::MANAGED_OPERATIONS, true)
            && $account->hasPermission(self::ADMIN_PERMISSION)
        ) {
            return AccessResult::allowed('Account holds "administer content" — full manage of content-group entities.');
        }

        // Additive only — never Forbidden, no opinion otherwise.
        return AccessResult::neutral('Not a content administrator (or unsupported operation).');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission(self::ADMIN_PERMISSION)) {
            return AccessResult::allowed('Account holds "administer content" — may create content-group entities.');
        }

        return AccessResult::neutral('Not a content administrator.');
    }
}
