<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

use InvalidArgumentException;
use Waaseyaa\EntityStorage\EntityRepository;

/**
 * Registry of `EntityRepository` instances keyed by entity type ID.
 *
 * Introduced by the listing pipeline (M-007 / WP05) so the resolver can
 * obtain the repository for a given listing's `$entityType` without
 * coupling to a runtime-specific service locator. The registry is a
 * plain map populated at boot by the host application (CLI / HTTP
 * kernels) from the entity-type manager. Tests construct it directly.
 *
 * Internal composition map populated by the listing service provider.
 */
final class EntityRepositoryRegistry
{
    /**
     * @var array<string, EntityRepository>
     */
    private array $repositories = [];

    /**
     * @param array<string, EntityRepository> $repositories Initial map (entityTypeId => repository).
     */
    public function __construct(array $repositories = [])
    {
        foreach ($repositories as $entityTypeId => $repo) {
            $this->register($entityTypeId, $repo);
        }
    }

    /**
     * Register a repository for the given entity type ID.
     *
     * @param non-empty-string $entityTypeId
     */
    public function register(string $entityTypeId, EntityRepository $repository): void
    {
        // PHPDoc pins this to non-empty-string; callers are expected to honour
        // it. We trust the type narrowing rather than runtime-asserting it,
        // matching the rest of the framework's value-object conventions.
        $this->repositories[$entityTypeId] = $repository;
    }

    /**
     * Return the repository bound to the given entity type ID.
     *
     * @param non-empty-string $entityTypeId
     *
     * @throws InvalidArgumentException When no repository is registered for `$entityTypeId`.
     */
    public function for(string $entityTypeId): EntityRepository
    {
        if (!isset($this->repositories[$entityTypeId])) {
            throw new InvalidArgumentException(sprintf(
                'EntityRepositoryRegistry: no repository registered for entity type "%s".',
                $entityTypeId,
            ));
        }

        return $this->repositories[$entityTypeId];
    }

    /**
     * Whether a repository is registered for the given entity type ID.
     */
    public function has(string $entityTypeId): bool
    {
        return isset($this->repositories[$entityTypeId]);
    }
}
