<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;
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
final class ParentDelegatedAccessPolicy implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    /** @var \Closure(EntityBase): PolicySubjectViewInterface */
    private readonly \Closure $policySubjectAuthority;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
    ) {
        $this->policySubjectAuthority = \Closure::bind(
            static fn(EntityBase $entity): PolicySubjectViewInterface => $entity->valueContainer->entityPolicySubjectView(),
            null,
            EntityBase::class,
        );
    }

    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface
    {
        return new ParentDelegatedEntityReadPolicy($this->entityTypeManager, $this->accessHandler);
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new ParentDelegatedFieldReadPolicy($this->entityTypeManager, $this->accessHandler);
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (!$entity instanceof Attachment) {
            // Defensive: this policy only handles Attachment entities.
            return AccessResult::neutral('Not an Attachment entity.');
        }

        $subject = ($this->policySubjectAuthority)($entity);
        if ($subject->fields() !== ['parent_entity_id', 'parent_entity_type']) {
            return AccessResult::neutral('Attachment access requires the exact compiled parent reference.');
        }

        $parentType = (string) $subject->get('parent_entity_type');
        $parentId = (string) $subject->get('parent_entity_id');

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

/** Delegates attachment entity reads using only the compiled parent reference. @api */
final readonly class ParentDelegatedEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function __construct(
        private EntityTypeManagerInterface $entityTypeManager,
        private EntityAccessHandler $accessHandler,
    ) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        if ($structure->entityTypeId !== 'attachment'
            || $subject->fields() !== ['parent_entity_id', 'parent_entity_type']
        ) {
            return AccessResult::forbidden('Attachment access requires the exact compiled parent reference.');
        }

        return $this->delegate($principal, $subject, $operation);
    }

    private function delegate(
        AuthorizationPrincipalInterface $principal,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        $parentType = (string) $subject->get('parent_entity_type');
        $parentId = (string) $subject->get('parent_entity_id');
        if ($parentType === '' || $parentId === '') {
            return AccessResult::neutral('Attachment has no parent entity reference.');
        }

        $parent = $this->entityTypeManager->getRepository($parentType)->find($parentId);
        if ($parent === null) {
            return AccessResult::neutral('Parent entity not found.');
        }

        return $this->accessHandler->check($parent, $operation, $principal);
    }
}

/** Delegates non-selector protected field reads using the compiled parent reference. @api */
final readonly class ParentDelegatedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function __construct(
        private EntityTypeManagerInterface $entityTypeManager,
        private EntityAccessHandler $accessHandler,
    ) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        if ($structure->entityTypeId !== 'attachment'
            || in_array($fieldName, ['parent_entity_id', 'parent_entity_type'], true)
            || $subject->fields() !== ['parent_entity_id', 'parent_entity_type']
        ) {
            return AccessResult::forbidden('Attachment field access requires the exact compiled parent reference.');
        }

        $parentType = (string) $subject->get('parent_entity_type');
        $parentId = (string) $subject->get('parent_entity_id');
        if ($parentType === '' || $parentId === '') {
            return AccessResult::forbidden('Attachment has no parent entity reference.');
        }

        $parent = $this->entityTypeManager->getRepository($parentType)->find($parentId);
        if ($parent === null) {
            return AccessResult::forbidden('Parent entity not found.');
        }

        return $this->accessHandler->check($parent, 'view', $principal);
    }
}
