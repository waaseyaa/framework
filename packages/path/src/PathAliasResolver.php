<?php

declare(strict_types=1);

namespace Waaseyaa\Path;

use Waaseyaa\Entity\Storage\EntityStorageInterface;

final readonly class PathAliasResolver
{
    public function __construct(
        private EntityStorageInterface $pathAliasStorage,
    ) {}

    public function resolve(string $alias, string $langcode = 'en'): ?ResolvedPath
    {
        if ($alias === '' || !str_starts_with($alias, '/')) {
            return null;
        }

        $query = $this->pathAliasStorage->getQuery()
            ->condition('alias', $alias)
            ->condition('status', 1)
            ->condition('langcode', $langcode)
            ->range(0, 1);

        $ids = $query->execute();
        if ($ids === []) {
            return null;
        }

        $entity = $this->pathAliasStorage->load($ids[0]);
        if (!$entity instanceof PathAlias) {
            return null;
        }

        return $this->resolvePathAlias($entity);
    }

    public function resolvePathAlias(PathAlias $alias): ?ResolvedPath
    {
        $systemPath = $alias->getPath();
        if ($systemPath === '' || !str_starts_with($systemPath, '/')) {
            return null;
        }

        if (!preg_match('#^/([a-z_][a-z0-9_]*)/([^/]+)$#i', $systemPath, $matches)) {
            return null;
        }

        $entityTypeId = strtolower($matches[1]);
        $rawId = $matches[2];
        $entityId = ctype_digit($rawId) ? (int) $rawId : $rawId;

        return new ResolvedPath(
            alias: $alias->getAlias(),
            systemPath: $systemPath,
            entityTypeId: $entityTypeId,
            entityId: $entityId,
        );
    }
}
