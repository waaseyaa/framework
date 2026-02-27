<?php

declare(strict_types=1);

namespace Aurora\Path;

/**
 * Interface for path alias management.
 *
 * Implementations map between internal system paths and clean URL aliases,
 * with support for language-specific lookups.
 */
interface PathAliasManagerInterface
{
    /**
     * Get the alias for a system path.
     *
     * Returns the alias if found, or the original path if not.
     *
     * @param string $path The internal system path (e.g. '/node/42').
     * @param string $langcode The language code to match against.
     *
     * @return string The alias if found, or the original path.
     */
    public function getAliasByPath(string $path, string $langcode = 'en'): string;

    /**
     * Get the system path for an alias.
     *
     * Returns the system path if found, or the original alias if not.
     *
     * @param string $alias The clean URL alias (e.g. '/about-us').
     * @param string $langcode The language code to match against.
     *
     * @return string The system path if found, or the original alias.
     */
    public function getPathByAlias(string $alias, string $langcode = 'en'): string;

    /**
     * Check if a given alias already exists.
     *
     * @param string $alias The clean URL alias to check.
     * @param string $langcode The language code to match against.
     *
     * @return bool TRUE if the alias exists and is published, FALSE otherwise.
     */
    public function aliasExists(string $alias, string $langcode = 'en'): bool;
}
