<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Fixtures;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;

/**
 * Entity access policy that hides individual rows (by slug) and individual
 * historical revisions (by revision id) from every principal.
 *
 * Deliberately says nothing about the acting principal's permissions: the
 * point of the fixture is that holding a coarse publishing capability must not
 * substitute for a per-entity access decision.
 */
final class SlugScopedViewPolicy implements AccessPolicyInterface
{
    /**
     * @param list<string> $deniedSlugs         Slugs whose entity is invisible.
     * @param list<int>    $deniedRevisionIds   Revision ids that are invisible even
     *                                          when the entity itself is visible.
     * @param list<string> $deniedBundles       Bundles that are invisible wholesale.
     */
    public function __construct(
        private readonly string $entityTypeId,
        private readonly array $deniedSlugs = [],
        private readonly array $deniedRevisionIds = [],
        private readonly array $deniedBundles = [],
        private readonly bool $allowCreate = true,
    ) {}

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === GateInterface::VIEW_REVISION) {
            $revisionId = $entity instanceof RevisionableEntityInterface ? (int) $entity->revisionId() : 0;
            if (\in_array($revisionId, $this->deniedRevisionIds, true)) {
                return AccessResult::forbidden('This revision is hidden by policy.');
            }

            // Neutral so the framework's documented view_revision → view
            // fallback decides; the entity-level opinion still applies.
            return AccessResult::neutral('No revision-level opinion.');
        }

        if (\in_array($entity->bundle(), $this->deniedBundles, true)) {
            return AccessResult::forbidden('This bundle is hidden by policy.');
        }

        if (\in_array((string) $entity->get('slug'), $this->deniedSlugs, true)) {
            return AccessResult::forbidden('This entity is hidden by policy.');
        }

        return AccessResult::allowed('Visible to every principal.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return $this->allowCreate
            ? AccessResult::allowed('Create is permitted.')
            : AccessResult::forbidden('Create is denied.');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === $this->entityTypeId;
    }
}
