<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Prevents deletion of vocabulary config rows while term rows reference them.
 *
 * This read-based check is the primary enforcement mechanism. The additive
 * restrictive foreign key ({@see VocabularyReferenceConstraint}) is a
 * database-level backstop against direct/batch/concurrent deletes that
 * bypass this policy — it is installed exclusively by coordinated schema
 * sync (`db:init`, `schema:sync`), never here: this policy runs on ordinary
 * request traffic (every delete-access check), so it must never perform
 * schema DDL (#2761).
 */
#[PolicyAttribute(entityType: 'taxonomy_vocabulary')]
final class VocabularyAccessPolicy implements AccessPolicyInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'taxonomy_vocabulary';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'delete') {
            return AccessResult::neutral();
        }

        $terms = $this->entityTypeManager->getRepository('taxonomy_term')->findBy(
            ['vid' => (string) $entity->id()],
            limit: 1,
        );
        if ($terms !== []) {
            return AccessResult::forbidden('This vocabulary contains terms and cannot be deleted until they are removed.');
        }

        return AccessResult::neutral('The vocabulary has no referencing terms.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}
