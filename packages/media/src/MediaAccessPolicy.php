<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for media entities.
 *
 * Mirrors the Node access model: admin bypass, ownership checks for
 * edit/delete, published status for view, bundle-specific permissions.
 */
#[PolicyAttribute(entityType: 'media')]
final class MediaAccessPolicy implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    /** @var \Closure(Media): PolicySubjectViewInterface */
    private readonly \Closure $ownerSubject;

    public function __construct()
    {
        $this->ownerSubject = \Closure::bind(
            static fn(Media $media): PolicySubjectViewInterface => $media->valueContainer->entityPolicySubjectView(),
            null,
            EntityBase::class,
        );
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'media';
    }

    public function protectedEntityReadPolicy(): ?ProtectedEntityReadPolicyInterface
    {
        return null;
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new MediaProtectedFieldReadPolicy();
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer media')) {
            return AccessResult::allowed('User has "administer media" permission.');
        }

        assert($entity instanceof Media);

        $bundle = $entity->bundle();
        $ownerId = $this->ownerId($entity);
        $isOwner = $ownerId !== null && (string) $account->id() === (string) $ownerId;

        return match ($operation) {
            'view' => $this->viewAccess($entity, $account, $isOwner),
            'update' => $this->editAccess($bundle, $account, $isOwner),
            'delete' => $this->deleteAccess($bundle, $account, $isOwner),
            default => AccessResult::neutral("No opinion on '$operation' operation."),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer media')) {
            return AccessResult::allowed('User has "administer media" permission.');
        }

        if ($account->hasPermission("create $bundle media")) {
            return AccessResult::allowed("User has 'create $bundle media' permission.");
        }

        return AccessResult::neutral("User lacks 'create $bundle media' permission.");
    }

    private function viewAccess(Media $media, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($media->isPublished()) {
            if ($account->hasPermission('access media')) {
                return AccessResult::allowed('Published media and user has "access media" permission.');
            }

            return AccessResult::neutral('User lacks "access media" permission.');
        }

        if ($isOwner && $account->hasPermission('view own unpublished media')) {
            return AccessResult::allowed('Owner viewing own unpublished media.');
        }

        return AccessResult::neutral('User cannot view this unpublished media.');
    }

    private function editAccess(string $bundle, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($account->hasPermission("edit any $bundle media")) {
            return AccessResult::allowed("User has 'edit any $bundle media' permission.");
        }

        if ($isOwner && $account->hasPermission("edit own $bundle media")) {
            return AccessResult::allowed("Owner has 'edit own $bundle media' permission.");
        }

        return AccessResult::neutral("User lacks edit permission for $bundle media.");
    }

    private function deleteAccess(string $bundle, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($account->hasPermission("delete any $bundle media")) {
            return AccessResult::allowed("User has 'delete any $bundle media' permission.");
        }

        if ($isOwner && $account->hasPermission("delete own $bundle media")) {
            return AccessResult::allowed("Owner has 'delete own $bundle media' permission.");
        }

        return AccessResult::neutral("User lacks delete permission for $bundle media.");
    }

    private function ownerId(Media $media): int|string|null
    {
        $subject = ($this->ownerSubject)($media);
        if ($subject->fields() !== ['uid']) {
            return null;
        }
        $ownerId = $subject->get('uid');

        return is_int($ownerId) || is_string($ownerId) ? $ownerId : null;
    }
}
