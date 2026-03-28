<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final readonly class OAuthToken
{
    /**
     * @param array<string> $scopes
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?\DateTimeImmutable $expiresAt,
        public array $scopes,
        public string $tokenType = 'Bearer',
    ) {}

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }
}
