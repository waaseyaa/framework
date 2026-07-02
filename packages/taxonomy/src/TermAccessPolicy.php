<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for taxonomy terms.
 *
 * Controls who can view, create, update, and delete taxonomy terms
 * based on per-vocabulary permissions and the global 'administer taxonomy' permission.
 */
#[PolicyAttribute(entityType: 'taxonomy_term')]
final class TermAccessPolicy implements AccessPolicyInterface
{
    /**
     * {@inheritdoc}
     */
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'taxonomy_term';
    }

    /**
     * {@inheritdoc}
     */
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        // 'administer taxonomy' grants full access for any operation.
        if ($account->hasPermission('administer taxonomy')) {
            return AccessResult::allowed('User has "administer taxonomy" permission.');
        }

        return match ($operation) {
            'view' => $this->checkViewAccess($entity, $account),
            'update' => $this->checkEditAccess($entity, $account),
            'delete' => $this->checkDeleteAccess($entity, $account),
            default => AccessResult::neutral("No opinion for operation '{$operation}'."),
        };
    }

    /**
     * {@inheritdoc}
     */
    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer taxonomy')) {
            return AccessResult::allowed('User has "administer taxonomy" permission.');
        }

        if ($account->hasPermission("create terms in {$bundle}")) {
            return AccessResult::allowed("User has \"create terms in {$bundle}\" permission.");
        }

        return AccessResult::neutral('User lacks permission to create terms in this vocabulary.');
    }

    /**
     * Check view access for a taxonomy term.
     *
     * Published terms are viewable with 'access content' (the public reading
     * permission). Unpublished terms are hidden from general readers — only an
     * editor of the term's vocabulary (or an `administer taxonomy` admin, handled
     * in {@see access()}) may view them. This mirrors NodeAccessPolicy's
     * published/unpublished split so unpublished terms do not leak via the
     * `view`-gated read surfaces (JSON:API collections, controllers).
     */
    private function checkViewAccess(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        // Absent status fails closed (treated as unpublished): a term whose status
        // cannot be determined must not be publicly viewable. The entity-side default
        // (Term seeds status = true in its constructor) is a separate product decision
        // and is deliberately unchanged — this only hardens the access-check fallback.
        $published = (bool) ($entity->get('status') ?? false);

        if ($published) {
            if ($account->hasPermission('access content')) {
                return AccessResult::allowed('Published term and user has "access content" permission.');
            }

            return AccessResult::neutral('User lacks "access content" permission.');
        }

        // Unpublished term: visible only to an editor of its vocabulary.
        $vid = $entity->bundle();
        if ($account->hasPermission("edit terms in {$vid}")) {
            return AccessResult::allowed("Editor with \"edit terms in {$vid}\" may view its unpublished terms.");
        }

        return AccessResult::neutral('User cannot view this unpublished term.');
    }

    /**
     * Check edit/update access for a taxonomy term.
     */
    private function checkEditAccess(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        $vid = $entity->bundle();

        if ($account->hasPermission("edit terms in {$vid}")) {
            return AccessResult::allowed("User has \"edit terms in {$vid}\" permission.");
        }

        return AccessResult::neutral("User lacks permission to edit terms in vocabulary '{$vid}'.");
    }

    /**
     * Check delete access for a taxonomy term.
     */
    private function checkDeleteAccess(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        $vid = $entity->bundle();

        if ($account->hasPermission("delete terms in {$vid}")) {
            return AccessResult::allowed("User has \"delete terms in {$vid}\" permission.");
        }

        return AccessResult::neutral("User lacks permission to delete terms in vocabulary '{$vid}'.");
    }
}
