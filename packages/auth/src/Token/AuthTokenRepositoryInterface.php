<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Token;

/** @internal */
interface AuthTokenRepositoryInterface
{
    /**
     * Create a token for the given user and type.
     *
     * Revokes any existing tokens of the same type for the same user.
     * Returns the plain token string (64-char hex). Only the HMAC-SHA256
     * hash is stored — the plain token is never persisted.
     *
     * @param int|string|null $userId NULL for invite tokens (no user yet)
     * @param string $type One of: password_reset, email_verification, invite
     * @param int $ttlSeconds Time-to-live in seconds
     * @param array<string, mixed>|null $meta Optional JSON-serializable metadata
     * @param int|string|null $createdBy Admin user ID for invite issuance
     */
    public function createToken(
        int|string|null $userId,
        string $type,
        int $ttlSeconds,
        ?array $meta = null,
        int|string|null $createdBy = null,
    ): string;

    /**
     * Validate a plain token against the stored hash.
     *
     * The result describes the token as it was at the moment of the read. It
     * resolves the token id and its metadata; it does not authorize a
     * single-use operation. Anything that consumes the token must re-establish
     * validity atomically through {@see self::consumeTokenIfAvailable()}.
     *
     * @return array{id: int, user_id: int|string|null, meta: array<string, mixed>|null}|null
     *         Returns token data if valid, null if invalid/expired/consumed.
     */
    public function validateToken(string $plainToken, string $type): ?array;

    /**
     * Mark a token as consumed (single-use enforcement).
     */
    public function consumeToken(int $tokenId): void;

    /**
     * Atomically consume an available token.
     *
     * The single conditional write that picks the winner is also the only
     * authority on the token's validity: it re-checks the token type, the
     * owning user, the unconsumed state, and expiry against the clock read
     * for that write. Callers must never treat an earlier validateToken()
     * result as establishing those facts — anything checked before this
     * statement can change before it runs.
     *
     * False means the token was absent, of another type, owned by another
     * user, already consumed, or expired.
     *
     * @param int|string|null $userId The user the token must be bound to;
     *                                NULL matches only an unowned (invite) token.
     */
    public function consumeTokenIfAvailable(int $tokenId, string $type, int|string|null $userId): bool;

    /**
     * Revoke all tokens for a user, optionally filtered by type.
     */
    public function revokeTokensForUser(int|string $userId, ?string $type = null): void;

    /**
     * Delete expired and consumed tokens. Returns count deleted.
     */
    public function pruneExpired(): int;
}
