<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

/** Exact credential-verification inputs obtained through audited authority. @api */
final readonly class UserCredentialSnapshot
{
    /**
     * @param bool        $active             Whether the account may authenticate at all.
     * @param string      $passwordHash       The current Waaseyaa hash, or '' when there is none.
     * @param string|null $legacyPasswordHash A credential imported from another system and pending
     *                                        one-time upgrade (#2544), or null. Kept separate from
     *                                        `$passwordHash` so a legacy value can never be handed to
     *                                        the native verifier, nor a current hash to a legacy one.
     */
    public function __construct(
        public bool $active,
        public string $passwordHash,
        public ?string $legacyPasswordHash = null,
    ) {}
}
