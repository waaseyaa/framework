<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/** Fail-closed dispatcher for entity and explicitly registered source resolvers. @api */
final class SearchCandidateResolverRegistry implements SearchCandidateResolverInterface
{
    /** @var array<string, SearchCandidateResolverInterface> */
    private readonly array $sourceResolvers;

    /** @param array<string, SearchCandidateResolverInterface> $sourceResolvers */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly SearchCandidateResolverInterface $entityResolver,
        array $sourceResolvers = [],
    ) {
        foreach ($sourceResolvers as $namespace => $resolver) {
            // @phpstan-ignore function.alreadyNarrowedType (runtime API boundary)
            if (!is_string($namespace) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $namespace) !== 1) {
                throw new \InvalidArgumentException('Search source resolver namespaces must be canonical lowercase identifiers.');
            }
            // @phpstan-ignore instanceof.alwaysTrue (runtime API boundary)
            if (!$resolver instanceof SearchCandidateResolverInterface) {
                throw new \InvalidArgumentException('Search source resolvers must implement SearchCandidateResolverInterface.');
            }
            if ($this->entityTypeManager->hasDefinition($namespace)) {
                throw new \InvalidArgumentException('Search source resolver namespaces cannot collide with entity type ids.');
            }
        }
        ksort($sourceResolvers);
        $this->sourceResolvers = $sourceResolvers;
    }

    public function resolve(
        SearchCandidateReference $reference,
        AuthorizationPrincipalInterface $principal,
    ): ?SearchCandidateProjection {
        if ($reference->entityType !== '' && $this->entityTypeManager->hasDefinition($reference->entityType)) {
            return $this->entityResolver->resolve($reference, $principal);
        }

        $namespace = $reference->namespace();
        if ($namespace !== null && $this->entityTypeManager->hasDefinition($namespace)) {
            return null;
        }
        if ($namespace === null || !isset($this->sourceResolvers[$namespace])) {
            return null;
        }

        $projection = $this->sourceResolvers[$namespace]->resolve($reference, $principal);

        return $projection?->id === $reference->documentId ? $projection : null;
    }
}
