<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for node entities.
 *
 * Checks permissions for viewing, updating, deleting, and creating nodes
 * based on the node type (bundle) and the relationship between the account
 * and the node author.
 */
#[PolicyAttribute(entityType: 'node')]
final class NodeAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        // Admin bypass.
        if ($account->hasPermission('administer nodes')) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        assert($entity instanceof Node);

        $type = $entity->getType();
        // An unauthenticated account is never an owner: the anonymous account's
        // id() is 0, which would otherwise equal an authorless node's
        // getAuthorId() ((int) (null) = 0), making anonymous the "owner" of every
        // authorless node and granting it any 'own'-scoped permission it was given.
        $isOwner = $account->isAuthenticated() && $account->id() === $entity->getAuthorId();

        return match ($operation) {
            'view' => $this->viewAccess($entity, $account, $isOwner),
            'update' => $this->editAccess($type, $account, $isOwner),
            'delete' => $this->deleteAccess($type, $account, $isOwner),
            default => AccessResult::neutral("No opinion on '$operation' operation."),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        // Admin bypass.
        if ($account->hasPermission('administer nodes')) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        if ($account->hasPermission("create $bundle content")) {
            return AccessResult::allowed("User has 'create $bundle content' permission.");
        }

        return AccessResult::neutral("User lacks 'create $bundle content' permission.");
    }

    /**
     * Check view access for a node.
     */
    private function viewAccess(Node $node, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($node->isPublished()) {
            if ($account->hasPermission('access content')) {
                return AccessResult::allowed('Published node and user has access content permission.');
            }

            return AccessResult::neutral('User lacks access content permission.');
        }

        // Unpublished node.
        if ($isOwner && $account->hasPermission('view own unpublished content')) {
            return AccessResult::allowed('Author viewing own unpublished content.');
        }

        return AccessResult::neutral('User cannot view this unpublished node.');
    }

    /**
     * Check edit access for a node.
     */
    private function editAccess(string $type, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($account->hasPermission("edit any $type content")) {
            return AccessResult::allowed("User has 'edit any $type content' permission.");
        }

        if ($isOwner && $account->hasPermission("edit own $type content")) {
            return AccessResult::allowed("Author has 'edit own $type content' permission.");
        }

        return AccessResult::neutral("User lacks edit permission for $type content.");
    }

    /**
     * Check delete access for a node.
     */
    private function deleteAccess(string $type, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($account->hasPermission("delete any $type content")) {
            return AccessResult::allowed("User has 'delete any $type content' permission.");
        }

        if ($isOwner && $account->hasPermission("delete own $type content")) {
            return AccessResult::allowed("Author has 'delete own $type content' permission.");
        }

        return AccessResult::neutral("User lacks delete permission for $type content.");
    }
}
