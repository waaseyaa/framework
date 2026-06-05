<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Waaseyaa\Access\AccountInterface;

final readonly class BearerTokenAuth implements McpAuthInterface
{
    /** @param array<string, AccountInterface> $tokens Token string → account mapping. */
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

        return $this->tokens[$token] ?? null;
    }

    /**
     * Expose the registered token strings for admin-surface fingerprinting.
     *
     * Used exclusively by `ServerConfigReadModel` to derive `tokenFingerprint`
     * values (NFR-003: only the first 16 chars of SHA-256 are returned; the
     * plaintext token never leaves this class or its direct caller).
     *
     * @return array<string, AccountInterface> Token → Account mapping.
     * @api
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }
}
