<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Value object representing an access check result.
 *
 * An AccessResult is one of four states: Allowed, Neutral, Forbidden, or Unauthenticated.
 * Results can be combined with AND (andIf) and OR (orIf) logic:
 *
 * - andIf: Forbidden/Unauthenticated wins over all; both must be Allowed for Allowed.
 * - orIf:  Forbidden/Unauthenticated wins over all; either Allowed yields Allowed.
 */
final readonly class AccessResult
{
    private function __construct(
        public AccessStatus $status,
        public string $reason = '',
    ) {}

    /**
     * Create an Allowed result.
     */
    public static function allowed(string $reason = ''): self
    {
        return new self(AccessStatus::ALLOWED, $reason);
    }

    /**
     * Create a Neutral result (no opinion).
     */
    public static function neutral(string $reason = ''): self
    {
        return new self(AccessStatus::NEUTRAL, $reason);
    }

    /**
     * Create a Forbidden result.
     */
    public static function forbidden(string $reason = ''): self
    {
        return new self(AccessStatus::FORBIDDEN, $reason);
    }

    /**
     * Create an Unauthenticated result (no valid identity).
     *
     * Semantically distinct from Forbidden: 401 vs 403.
     */
    public static function unauthenticated(string $reason = ''): self
    {
        return new self(AccessStatus::UNAUTHENTICATED, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->status === AccessStatus::ALLOWED;
    }

    public function isForbidden(): bool
    {
        return $this->status === AccessStatus::FORBIDDEN;
    }

    public function isNeutral(): bool
    {
        return $this->status === AccessStatus::NEUTRAL;
    }

    public function isUnauthenticated(): bool
    {
        return $this->status === AccessStatus::UNAUTHENTICATED;
    }

    /**
     * Combine with AND logic.
     *
     * - If either is Forbidden or Unauthenticated, that result wins.
     * - If both are Allowed, the result is Allowed.
     * - Otherwise, the result is Neutral.
     */
    public function andIf(self $other): self
    {
        // Unauthenticated wins over all (401 before 403).
        if ($this->isUnauthenticated()) {
            return $this;
        }
        if ($other->isUnauthenticated()) {
            return $other;
        }

        // Forbidden wins over allowed/neutral.
        if ($this->isForbidden()) {
            return $this;
        }
        if ($other->isForbidden()) {
            return $other;
        }

        // Both must be Allowed for Allowed.
        if ($this->isAllowed() && $other->isAllowed()) {
            return $this;
        }

        // At least one is Neutral, so result is Neutral.
        if ($this->isNeutral()) {
            return $this;
        }

        return $other;
    }

    /**
     * Combine with OR logic.
     *
     * - If either is Forbidden or Unauthenticated, that result wins.
     * - If either is Allowed, the result is Allowed.
     * - Otherwise, the result is Neutral.
     */
    public function orIf(self $other): self
    {
        // Unauthenticated wins over all (401 before 403).
        if ($this->isUnauthenticated()) {
            return $this;
        }
        if ($other->isUnauthenticated()) {
            return $other;
        }

        // Forbidden wins over allowed/neutral.
        if ($this->isForbidden()) {
            return $this;
        }
        if ($other->isForbidden()) {
            return $other;
        }

        // Either Allowed yields Allowed.
        if ($this->isAllowed()) {
            return $this;
        }
        if ($other->isAllowed()) {
            return $other;
        }

        // Both are Neutral.
        return $this;
    }
}
