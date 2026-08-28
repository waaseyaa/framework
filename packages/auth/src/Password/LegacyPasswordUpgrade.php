<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Password;

use Waaseyaa\Access\User\UserCredentialSnapshot;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\User\User;

/**
 * The single password-verification decision for the framework's login paths,
 * and the one-time upgrade that follows a legacy acceptance (#2544).
 *
 * A migration from another system arrives with credentials the framework cannot
 * verify. The alternative to this class is forcing every migrated member
 * through a password reset — which for a community roster is not a minor
 * inconvenience but a hard cutover that loses the people who do not complete
 * it. So a legacy credential is accepted exactly once, and that acceptance is
 * what destroys it.
 *
 * ## Order, and why it is not negotiable
 *
 * 1. The account must be active. A disabled account never reaches a verifier.
 * 2. The CURRENT hash is tried first, against `pass` only.
 * 3. Only if there is no current hash is the legacy credential tried, against
 *    `legacy_pass` only.
 *
 * Step 3's precondition is the load-bearing one. An account that already has a
 * current hash never consults its legacy value at all, so an upgraded account
 * cannot be pushed back onto the weaker credential — not by a stale row, not by
 * a partially-applied migration, not by a concurrent write. "Never downgrades a
 * current hash" is therefore a property of the control flow rather than a rule
 * someone must remember.
 *
 * ## What the upgrade is allowed to do to a login
 *
 * Nothing. The credential was valid; the rewrite is bookkeeping. A failed
 * upgrade is logged without the credential and the login proceeds, leaving the
 * account exactly as it was so the next login retries. The rewrite is a single
 * `save()` that sets `pass` and clears `legacy_pass` together, so there is no
 * window in which an account has neither.
 *
 * Concurrency: two simultaneous logins both verify, both rewrite, and each
 * writes an independently-valid hash over an entity re-read immediately before
 * the save. Neither can restore `legacy_pass`, because the value written for it
 * is always null and never a copy of what was read.
 *
 * @api
 */
final readonly class LegacyPasswordUpgrade
{
    private LoggerInterface $logger;

    public function __construct(
        private EntityTypeManagerInterface $entityTypeManager,
        private LegacyPasswordVerifierChain $legacyVerifiers = new LegacyPasswordVerifierChain(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Whether `$password` authenticates `$user`, upgrading a legacy credential
     * in place when that is what accepted it.
     *
     * Returns the same `false` for a disabled account, an absent credential, an
     * unsupported legacy format, and a wrong password: the caller must not be
     * able to tell them apart, and neither must its user.
     */
    public function verify(User $user, string $password, UserCredentialSnapshot $credentials): bool
    {
        if (!$credentials->active) {
            return false;
        }

        if ($credentials->passwordHash !== '') {
            // A current hash is present: the legacy value, if any, is stale
            // migration residue and is never consulted.
            return password_verify($password, $credentials->passwordHash);
        }

        $legacy = $credentials->legacyPasswordHash;
        if ($legacy === null || !$this->legacyVerifiers->verify($password, $legacy)) {
            return false;
        }

        $this->upgrade($user, $password);

        return true;
    }

    /**
     * Replace the accepted legacy credential with a current hash, in one save.
     *
     * Best effort by contract: the caller has already been authenticated, and a
     * storage failure here must not turn a valid login into a rejected one.
     *
     * ## Write-only, deliberately
     *
     * This method never READS a credential field off the entity. `pass` and
     * `legacy_pass` are {@see \Waaseyaa\Entity\FieldReadLevel::Internal}, so
     * `$user->getLegacyPassword()` requires an audited capability that a login
     * request does not hold — reading one here would throw `FieldReadDenied`
     * inside the `catch` below and silently turn every upgrade into a no-op.
     * Everything needed is already in hand: the snapshot said there was a
     * legacy credential, the verifier said the password matches it, and the new
     * hash is computed locally.
     *
     * There is therefore no "has someone already upgraded this?" check, and it
     * would buy nothing: a concurrent login writes an independently-valid hash
     * for the same password and also writes `legacy_pass = null`. The value
     * written for the legacy field is a literal null on every path and is never
     * a copy of what was read, so no ordering of concurrent writes can restore
     * it. A redundant second hash is the entire cost.
     */
    private function upgrade(User $user, string $password): void
    {
        $hash = password_hash($password, \PASSWORD_DEFAULT);

        try {
            $repository = $this->entityTypeManager->getRepository('user');

            // Re-read immediately before writing, so the object being saved is
            // not one another request has since moved on from: a stale object
            // would write back every field it was loaded with.
            $fresh = $repository->find((string) $user->id());
            if (!$fresh instanceof User) {
                return;
            }

            $fresh->setPassword($hash);
            $fresh->setLegacyPassword(null);
            $repository->save($fresh);
        } catch (\Throwable $e) {
            // Class and account id only. The credential, the password, and the
            // exception message never reach a log — the first two are password
            // equivalents and the third can quote them.
            $this->logger->error('auth.legacy_password_upgrade_failed', [
                'user_id' => (string) $user->id(),
                'exception' => $e::class,
            ]);

            return;
        }

        // Only once storage agrees: the object the caller still holds must not
        // keep reporting a credential that no longer exists.
        $user->setPassword($hash);
        $user->setLegacyPassword(null);
    }
}
