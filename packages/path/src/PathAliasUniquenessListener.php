<?php

declare(strict_types=1);

namespace Waaseyaa\Path;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;

/**
 * Enforces (alias, langcode) uniqueness for path_alias entities.
 *
 * This listener hooks {@see BeforeSaveEvent} — the provably-live,
 * entity-agnostic pre-write hook dispatched by
 * {@see \Waaseyaa\EntityStorage\EntityRepository::doSave()} — and throws
 * {@see AbortOperationException} to halt the save when a conflicting row
 * already exists.
 *
 * Scoping is (alias, langcode): the same alias under a different langcode is
 * legitimate (PathAliasResolver::resolve() matches on alias+langcode) and is
 * NOT treated as a conflict.
 */
final class PathAliasUniquenessListener
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function __invoke(BeforeSaveEvent $event): void
    {
        $entity = $event->entity();
        if (!$entity instanceof PathAlias) {
            return;
        }

        // Defensive re-normalization: PathAlias's own constructor/setAlias()
        // already store NFC, but this listener must not assume every caller
        // reached the entity through those paths — the JSON:API PATCH path
        // applies attributes via the generic EntityBase::set() (see
        // JsonApiController's update loop), which bypasses setAlias() and can
        // otherwise persist raw NFD bytes.
        $normalizedAlias = PathAlias::normalizeAlias($entity->getAlias());
        $langcode = $entity->getLanguage();

        // BeforeSaveEvent is dispatched by EntityRepository::doSave() BEFORE it
        // reads $entity->toArray() for the write, so rewriting the alias here is
        // what actually persists. This makes the listener the single universal
        // NFC-storage guarantee across EVERY write path (create AND PATCH), with
        // the entity-side normalization kept as defense-in-depth. Use the
        // generic set() (not setAlias()) to skip re-running the leading-slash
        // validation on an already-validated value, and only write when the
        // stored bytes actually differ.
        if ($normalizedAlias !== $entity->getAlias()) {
            $entity->set('alias', $normalizedAlias);
        }

        $repository = $this->entityTypeManager->getRepository('path_alias');

        // System-context uniqueness check, not a user-facing view: this must
        // see every existing alias row regardless of the acting account's
        // entity-level access, mirroring PathAliasResolver's justification
        // (packages/path/src/PathAliasResolver.php) for the same accessCheck(false)
        // opt-out. See docs/security/sql-entity-query-access-check-bypass-audit.md.
        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->condition('alias', $normalizedAlias)
            ->condition('langcode', $langcode)
            ->execute();

        // Self-exclusion: on an UPDATE, the entity's own row is expected to
        // match; on a CREATE, id() is null and nothing is excluded.
        $selfId = $entity->id();
        $conflicting = array_filter(
            $ids,
            static fn(int|string $id): bool => $selfId === null || (string) $id !== (string) $selfId,
        );

        if ($conflicting !== []) {
            throw new AbortOperationException(sprintf(
                'A path alias "%s" already exists for langcode "%s".',
                $normalizedAlias,
                $langcode,
            ));
        }
    }
}
