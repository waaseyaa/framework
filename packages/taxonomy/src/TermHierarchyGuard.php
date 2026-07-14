<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;

/**
 * Rejects invalid taxonomy parentage at the persistence boundary.
 *
 * Tree traversal remains a read concern; persisted terms are guaranteed not
 * to introduce self-parenting, cross-vocabulary edges, or cycles.
 */
final class TermHierarchyGuard
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function __invoke(EntityEvent $event): void
    {
        $term = $event->entity;
        if (!$term instanceof Term || $term->isRoot()) {
            return;
        }

        $termId = $term->id();
        $parentId = $term->getParentId();
        if ($parentId === null || $parentId === 0) {
            return;
        }

        if ($termId !== null && (string) $termId === (string) $parentId) {
            throw new \DomainException('A taxonomy term cannot be its own parent.');
        }

        $repository = $this->entityTypeManager->getRepository('taxonomy_term');
        $visited = [];
        $ancestorId = $parentId;

        while (true) {
            $key = (string) $ancestorId;
            if (isset($visited[$key]) || ($termId !== null && $key === (string) $termId)) {
                throw new \DomainException('The taxonomy parent assignment would create a cycle.');
            }
            $visited[$key] = true;

            $ancestor = $repository->find($key);
            if (!$ancestor instanceof Term) {
                throw new \DomainException("Taxonomy parent term {$key} does not exist.");
            }
            if ($ancestor->getVocabularyId() !== $term->getVocabularyId()) {
                throw new \DomainException('A taxonomy parent must belong to the same vocabulary as its child.');
            }

            $next = $ancestor->getParentId();
            if ($next === null || $next === 0) {
                return;
            }
            $ancestorId = $next;
        }
    }
}
