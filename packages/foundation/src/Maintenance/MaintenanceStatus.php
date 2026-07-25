<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Maintenance;

/**
 * Immutable snapshot of the maintenance flag as read from disk.
 *
 * `ambiguous` is set when the flag file existed but could not be read or parsed
 * cleanly. Such a state is reported as {@see $active} `true` (fail-closed): a
 * mid-swap, partially written, or corrupt flag must never fail open into serving
 * live traffic. See docs/specs/operations-playbooks.md "Maintenance mode".
 *
 * @api
 */
final readonly class MaintenanceStatus
{
    public function __construct(
        public bool $active,
        public bool $ambiguous = false,
        public int $retryAfter = 120,
        public ?string $message = null,
        public ?string $since = null,
    ) {}

    public static function inactive(): self
    {
        return new self(active: false);
    }
}
