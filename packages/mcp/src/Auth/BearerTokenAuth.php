<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Waaseyaa\Access\AccountInterface;

final readonly class BearerTokenAuth implements McpAuthInterface
{
    /**
     * @param array<string|int, AccountInterface> $tokens Token string → account
     *        mapping. Keys are configured as strings, but PHP coerces purely
     *        numeric token strings to int array keys — the key type reflects
     *        that runtime reality (FR-005 key-coercion guard).
     */
    public function __construct(
        private array $tokens,
    ) {}

    public function authenticate(?string $authorizationHeader): ?AccountInterface
    {
        if ($authorizationHeader === null || $authorizationHeader === '') {
            return null;
        }

        if (!\str_starts_with(\strtolower($authorizationHeader), 'bearer ')) {
            return null;
        }

        $token = \substr($authorizationHeader, 7);

        // FR-005: constant-time comparison over the candidate set. The
        // `(string)` cast is load-bearing — PHP coerces purely numeric token
        // strings to int array keys, and hash_equals() requires strings.
        // There is deliberately no `break` on match: whole-call timing must
        // not depend on WHICH entry matches, so every configured token is
        // compared on every call.
        $matched = null;
        foreach ($this->tokens as $candidate => $account) {
            if (\hash_equals((string) $candidate, $token)) {
                $matched = $account;
            }
        }

        if ($matched !== null
            && \method_exists($matched, 'isActive')
            && !$matched->isActive()
        ) {
            // FR-006: blocked/inactive account fails closed — indistinguishable
            // from an unknown token. Duck-typed: AccountInterface has no status
            // member; User::isActive() is the canonical liveness accessor and
            // an mcp→user manifest edge for one method check is not warranted
            // (mission request-surface-hardening research D4). Account objects
            // without isActive() own their liveness semantics and pass through.
            return null;
        }

        return $matched;
    }

    /**
     * Expose the registered token strings for admin-surface fingerprinting.
     *
     * Used exclusively by `ServerConfigReadModel` to derive `tokenFingerprint`
     * values (NFR-003: only the first 16 chars of SHA-256 are returned; the
     * plaintext token never leaves this class or its direct caller).
     *
     * @return array<string|int, AccountInterface> Token → Account mapping
     *         (purely numeric token strings appear as int keys — PHP
     *         array-key coercion).
     * @api
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }
}
