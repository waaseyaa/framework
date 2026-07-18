<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for node entities.
 *
 * Entity-level: checks permissions for viewing, updating, deleting, and
 * creating nodes based on the node type (bundle) and the relationship between
 * the account and the node author.
 *
 * Field-level (open-by-default, Forbidden restricts): node's system/identity
 * fields — `uid` (authorship), `type` (the readOnly bundle), and the
 * `created`/`changed` timestamps — are edit-forbidden for everyone except an
 * `administer nodes` admin. Without this gate the JSON:API / agent write paths
 * (which run `checkFieldAccess('edit')` open-by-default) let any account that
 * passes the entity `update` check — e.g. an author with `edit own {type}
 * content` — reassign authorship, change the bundle, or forge timestamps via
 * `PATCH /api/node/{id}`. The publication fields `status`/`workflow_state`
 * are permission-gated (CW-v1 WP-0, `docs/specs/content-workflow.md`);
 * `promote`/`sticky` remain ungated pending the engine.
 */
#[PolicyAttribute(entityType: 'node')]
final class NodeAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    /**
     * Interim CW-v1 publish gate (WP-0, spec: docs/specs/content-workflow.md).
     * Named after the engine's editorial publish transition so nothing renames
     * when TransitionService lands.
     */
    public const string PUBLISH_PERMISSION = 'use editorial transition publish';

    /** @var list<string> */
    private const PUBLISH_GATED_FIELDS = ['status', 'workflow_state'];

    /**
     * System/identity fields that only an `administer nodes` admin may edit.
     *
     * @var list<string>
     */
    private const ADMIN_ONLY_EDIT_FIELDS = ['uid', 'type', 'created', 'changed'];

    private readonly NodeAuthorizationSnapshotReader $authorizationReader;

    public function __construct(?NodeAuthorizationSnapshotReader $authorizationReader = null)
    {
        $this->authorizationReader = $authorizationReader ?? new NodeAuthorizationSnapshotReader();
    }

    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface
    {
        return new NodeProtectedReadPolicy();
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new NodeProtectedReadPolicy();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view' && in_array($fieldName, ['status', 'uid', 'workflow_state'], true)) {
            return $account->hasPermission('administer nodes')
                ? AccessResult::neutral('Node administrator view is decided by the protected field policy.')
                : AccessResult::forbidden('Protected node fields are not part of the ordinary view projection.');
        }

        // This gate restricts writes only; view of these fields is unaffected.
        if ($operation !== 'edit') {
            return AccessResult::neutral('Node field gate restricts edit only.');
        }

        if ($account->hasPermission('administer nodes')) {
            return AccessResult::neutral('Admin may edit any node field.');
        }

        // CW-v1 WP-0: publication is permission-gated on create AND update. An
        // account with only edit/create permissions must not self-publish to
        // anonymous visibility (audit D1). Unlike ADMIN_ONLY_EDIT_FIELDS below,
        // this gate has no isNew() carve-out.
        if (\in_array($fieldName, self::PUBLISH_GATED_FIELDS, true)
            && !$account->hasPermission(self::PUBLISH_PERMISSION)) {
            return AccessResult::forbidden(\sprintf(
                "Field '%s' requires the '%s' permission.",
                $fieldName,
                self::PUBLISH_PERMISSION,
            ));
        }

        // These fields are settable at CREATE (authoring a new node — `type` is
        // the required bundle, `uid`/`created` are authorship/timestamp) but
        // immutable on UPDATE of an existing node for non-admins: that is the
        // escalation (reassigning authorship, changing the readOnly bundle, or
        // forging timestamps on content that already exists).
        if (!$entity->isNew() && \in_array($fieldName, self::ADMIN_ONLY_EDIT_FIELDS, true)) {
            return AccessResult::forbidden(\sprintf("Field '%s' on an existing node is editable only with 'administer nodes'.", $fieldName));
        }

        return AccessResult::neutral("No field-edit opinion on '$fieldName'.");
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        // Admin bypass.
        if ($account->hasPermission('administer nodes')) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        assert($entity instanceof Node);

        $authorization = $this->authorizationReader->read($entity);
        $type = $authorization->type;
        // An unauthenticated account is never an owner: the anonymous account's
        // id() is 0, which would otherwise equal an authorless node's
        // getAuthorId() ((int) (null) = 0), making anonymous the "owner" of every
        // authorless node and granting it any 'own'-scoped permission it was given.
        $isOwner = $account->isAuthenticated() && $authorization->authorId !== null
            && (string) $account->id() === (string) $authorization->authorId;

        return match ($operation) {
            'view' => $this->viewAccess($authorization->published, $account, $isOwner),
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
    private function viewAccess(bool $published, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($published) {
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
