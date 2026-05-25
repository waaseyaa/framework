<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

/**
 * Value object representing a stored refresh token row.
 *
 * @api
 */
final readonly class RefreshTokenRecord
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $jti,
        public string $token,
        public string $accessTokenJti,
        public string $clientId,
        public string $accountId,
        public array $scopes,
        public int $authTime,
        public string $chainRootJti,
        public int $issuedAt,
        public int $expiresAt,
        public ?int $revokedAt,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
