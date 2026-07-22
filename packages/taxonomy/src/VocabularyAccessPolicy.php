<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/** Prevents deletion of vocabulary config rows while term rows reference them. */
#[PolicyAttribute(entityType: 'taxonomy_vocabulary')]
final class VocabularyAccessPolicy implements AccessPolicyInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?DatabaseInterface $database = null,
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

        if ($this->database !== null) {
            new VocabularyReferenceConstraint($this->database)->ensure();
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
