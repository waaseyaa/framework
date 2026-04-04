<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Community;

/**
 * Request-scoped community context.
 *
 * Holds the active community ID for the current request. When set,
 * entity storage drivers that are wired with community scoping will
 * automatically restrict queries to the active community.
 *
 * Not set during CLI execution, admin superuser sessions, or any
 * context where cross-community access is intentional.
 */
interface CommunityContextInterface
{
    /**
     * Set the active community for the current request.
     */
    public function set(string $communityId): void;

    /**
     * Return the active community ID, or null if no community is set.
     */
    public function get(): ?string;

    /**
     * Clear the active community context.
     */
    public function clear(): void;

    /**
     * Return true if a community context is currently active.
     */
    public function isActive(): bool;
}
