<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Community;

/**
 * Default implementation of the request-scoped community context.
 *
 * Registered as a singleton in FoundationServiceProvider. Holds one
 * community ID per request; CommunityMiddleware sets it from the
 * incoming request and clears it in a finally block after the response.
 */
final class CommunityContext implements CommunityContextInterface
{
    private ?string $communityId = null;

    public function set(string $communityId): void
    {
        $this->communityId = $communityId;
    }

    public function get(): ?string
    {
        return $this->communityId;
    }

    public function clear(): void
    {
        $this->communityId = null;
    }

    public function isActive(): bool
    {
        return $this->communityId !== null;
    }
}
