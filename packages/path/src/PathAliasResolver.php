<?php

declare(strict_types=1);

namespace Waaseyaa\Path;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final readonly class PathAliasResolver
{
    public function __construct(
        private EntityStorageInterface $pathAliasStorage,
        // C-22 WP2: the query builder now lives on the repository.
        private EntityRepositoryInterface $pathAliasRepository,
    ) {}

    public function resolve(string $alias, string $langcode = 'en'): ?ResolvedPath
    {
        if ($alias === '' || !str_starts_with($alias, '/')) {
            return null;
        }

        // System-context URL → entity-id lookup. Path aliases must resolve
        // globally; entity-level access is enforced when the resolved entity
        // is subsequently loaded by the caller.
        // See docs/security/sql-entity-query-access-check-bypass-audit.md.
        $query = $this->pathAliasRepository->getQuery()
            ->accessCheck(false)
            ->condition('alias', $alias)
            ->condition('langcode', $langcode)
            ->range(0, 20);

        $ids = $query->execute();
        if ($ids === []) {
            return null;
        }

        foreach ($ids as $id) {
            $entity = $this->pathAliasStorage->load($id);
            if (!$entity instanceof PathAlias || !$entity->isPublished()) {
                continue;
            }

            return $this->resolvePathAlias($entity);
        }

        return null;
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
