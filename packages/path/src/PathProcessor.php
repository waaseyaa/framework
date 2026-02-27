<?php

declare(strict_types=1);

namespace Aurora\Path;

/**
 * Processes URL paths by resolving aliases.
 *
 * Handles both inbound (alias -> system path) and outbound
 * (system path -> alias) path processing.
 */
final class PathProcessor
{
    public function __construct(
        private readonly PathAliasManagerInterface $aliasManager,
    ) {}

    /**
     * Process an incoming path, resolving aliases to system paths.
     *
     * @param string $path The incoming URL path (may be an alias).
     * @param string $langcode The language code.
     *
     * @return string The resolved system path, or the original path if no alias found.
     */
    public function processInbound(string $path, string $langcode = 'en'): string
    {
        return $this->aliasManager->getPathByAlias($path, $langcode);
    }

    /**
     * Process an outbound path, converting system paths to aliases.
     *
     * @param string $path The system path to convert.
     * @param string $langcode The language code.
     *
     * @return string The alias if found, or the original system path.
     */
    public function processOutbound(string $path, string $langcode = 'en'): string
    {
        return $this->aliasManager->getAliasByPath($path, $langcode);
    }
}
