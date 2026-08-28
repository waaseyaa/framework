<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Password;

/**
 * Verifies a password against a credential produced by another system, so a
 * migrated member can sign in once with the password they already have.
 *
 * This exists to be replaced by itself: a verifier's whole purpose is to let
 * {@see LegacyPasswordUpgrade} rewrite the credential it just accepted into a
 * current Waaseyaa hash, after which no verifier is ever consulted for that
 * account again. An implementation is a migration ramp, not an authentication
 * mechanism.
 *
 * ## Implementation contract
 *
 * - **`supports()` recognizes a FORMAT, not a value.** It must be a cheap,
 *   total function of the stored string, and must never consult the password.
 * - **`verify()` must never throw.** It is on the login path, where an
 *   exception is an availability defect and a distinguishable one is an oracle.
 *   Malformed input, an unrecognized format, and a wrong password all return
 *   `false`.
 * - **Cost parameters carried by the stored hash are attacker-adjacent.** A
 *   migration imports whatever the previous system stored, so an implementation
 *   MUST bound any work factor it reads out of the hash rather than trusting it.
 * - **Compare with `hash_equals()`**, not `===`.
 * - **Never log, wrap, or re-throw the stored credential.** It is a password
 *   equivalent for as long as it exists.
 *
 * @api
 */
interface LegacyPasswordVerifierInterface
{
    /**
     * Whether this verifier recognizes `$legacyHash`'s format.
     *
     * Recognizing a format is not a promise to accept it: a hash whose cost
     * parameter is out of bounds is recognized here and refused by
     * {@see self::verify()}, so an operator can tell "we do not support this
     * format" from "we support it and this one is unusable".
     */
    public function supports(string $legacyHash): bool;

    /** Whether `$password` produces `$legacyHash`. Never throws. */
    public function verify(string $password, string $legacyHash): bool;

    /**
     * A short, stable identifier for the format this verifier handles
     * (`phpass`, …), for operator diagnostics.
     *
     * It names the FORMAT and never any part of a stored value.
     */
    public function name(): string;
}
