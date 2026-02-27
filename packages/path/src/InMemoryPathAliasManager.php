<?php

declare(strict_types=1);

namespace Aurora\Path;

/**
 * In-memory implementation of the path alias manager.
 *
 * Stores aliases in a simple array for testing and lightweight usage.
 * Only published (active) aliases are considered for lookups.
 */
final class InMemoryPathAliasManager implements PathAliasManagerInterface
{
    /** @var PathAlias[] */
    private array $aliases = [];

    /**
     * Add a path alias to the manager.
     */
    public function addAlias(PathAlias $alias): void
    {
        $this->aliases[] = $alias;
    }

    public function getAliasByPath(string $path, string $langcode = 'en'): string
    {
        foreach ($this->aliases as $alias) {
            if (
                $alias->getPath() === $path
                && $alias->getLanguage() === $langcode
                && $alias->isPublished()
            ) {
                return $alias->getAlias();
            }
        }

        return $path;
    }

    public function getPathByAlias(string $alias, string $langcode = 'en'): string
    {
        foreach ($this->aliases as $pathAlias) {
            if (
                $pathAlias->getAlias() === $alias
                && $pathAlias->getLanguage() === $langcode
                && $pathAlias->isPublished()
            ) {
                return $pathAlias->getPath();
            }
        }

        return $alias;
    }

    public function aliasExists(string $alias, string $langcode = 'en'): bool
    {
        foreach ($this->aliases as $pathAlias) {
            if (
                $pathAlias->getAlias() === $alias
                && $pathAlias->getLanguage() === $langcode
                && $pathAlias->isPublished()
            ) {
                return true;
            }
        }

        return false;
    }
}
