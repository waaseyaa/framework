<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Token\Bearer;

/**
 * Durable bearer-token lifecycle store (#2177 F3).
 *
 * The contract every credentialed machine surface (the MCP write tier first)
 * authenticates against:
 *
 * - **No plaintext at rest.** `issue()`/`rotate()` return the secret exactly
 *   once via {@see IssuedBearerToken}; implementations persist only a
 *   verifier hash plus the non-secret id/fingerprint.
 * - **Expiry is mandatory** and bounded by {@see self::MAX_TTL_SECONDS};
 *   `verify()` enforces it against an injected clock, inclusive at the
 *   boundary.
 * - **Revocation is durable and immediate**; re-revoking an already revoked
 *   token is a no-op.
 * - **Rotation is atomic**: the successor becomes usable and the predecessor
 *   unusable as one operation, or neither happens.
 * - **Audience is enforced fail-closed** by `verify()`: a token issued for
 *   one audience never authenticates at another.
 * - **Fail closed on infrastructure trouble**: `verify()` answers null when
 *   the store cannot give a positive, durable answer; mutating operations
 *   throw {@see BearerTokenStoreException}.
 *
 * @api
 */
interface BearerTokenStoreInterface
{
    public const int MIN_TTL_SECONDS = 60;

    /** Safe maximum credential lifetime: 90 days. */
    public const int MAX_TTL_SECONDS = 7_776_000;

    /** Default credential lifetime: 30 days. */
    public const int DEFAULT_TTL_SECONDS = 2_592_000;

    /**
     * Issue a new token for a real account.
     *
     * @param int $accountUid The owning account's uid (> 0, never a sentinel).
     * @param string $audience The exact surface the token is valid for
     *        (e.g. `mcp:write`).
     * @param list<string> $scopes Capability strings the token is limited to;
     *        canonicalized (trimmed, deduplicated, sorted), never empty.
     * @param ?int $ttlSeconds Lifetime within [MIN_TTL_SECONDS, MAX_TTL_SECONDS];
     *        null means DEFAULT_TTL_SECONDS.
     * @param string $label Operator-facing display label (max 128 printable chars).
     *
     * @throws \InvalidArgumentException on any out-of-bounds input
     * @throws BearerTokenStoreException when the store cannot persist durably
     */
    public function issue(
        int $accountUid,
        string $audience,
        array $scopes,
        ?int $ttlSeconds = null,
        string $label = '',
    ): IssuedBearerToken;

    /**
     * Authenticate a presented bearer token for an audience.
     *
     * Fail-closed: malformed shape, unknown id, wrong secret, revoked,
     * expired, wrong audience, malformed stored record, and storage outage
     * all answer null — indistinguishably.
     */
    public function verify(string $presentedToken, string $audience): ?BearerTokenRecord;

    /**
     * Replace a live token with a successor carrying the same owner,
     * audience, scopes and label, revoking the predecessor atomically.
     *
     * @param ?int $ttlSeconds Successor lifetime; null re-uses the
     *        predecessor's original lifetime.
     *
     * @throws BearerTokenStoreException when the token is unknown, revoked,
     *         expired, or the swap cannot be made durable (in which case the
     *         predecessor remains the sole usable credential)
     */
    public function rotate(string $tokenId, ?int $ttlSeconds = null): IssuedBearerToken;

    /**
     * Durably revoke a token. Idempotent for an already-revoked token.
     *
     * @throws BearerTokenStoreException when the id is unknown or the
     *         revocation cannot be made durable
     */
    public function revoke(string $tokenId): void;

    public function find(string $tokenId): ?BearerTokenRecord;

    /**
     * Bounded listing for operator surfaces, newest first.
     *
     * @return list<BearerTokenRecord>
     */
    public function all(int $limit = 100): array;
}
