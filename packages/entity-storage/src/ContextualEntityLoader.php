<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Entity\EntityInterface;

/** @internal */
final class ContextualEntityLoader implements ContextualEntityLoaderInterface
{
    /** @var \Closure(list<int|string>): array<int|string, EntityInterface> */
    private readonly \Closure $loader;

    /** @param callable(list<int|string>): array<int|string, EntityInterface> $loader */
    public function __construct(private readonly object $boundary, callable $loader)
    {
        $this->loader = $loader(...);
    }

    public function authorizationBoundary(): object
    {
        return $this->boundary;
    }

    public function loadMultiple(array $ids): array
    {
        return ($this->loader)($ids);
    }
}
