<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\User\User;

/**
 * Orchestrates the two-factor authentication lifecycle for a User.
 *
 * Composes the existing {@see TwoFactorManager} primitives (TOTP + recovery
 * code generation/verification) with {@see EntityTypeManager} for User
 * persistence. Stateless service; all per-user state lives on the User
 * entity's two_factor_secret + two_factor_recovery_codes_hash fields.
 *
 * @api
 */
final class TwoFactorService
{
    private const string ISSUER = 'Waaseyaa';

    public function __construct(
        private readonly TwoFactorManager $manager,
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    /**
     * Generate a fresh secret + recovery codes for the user. Does NOT persist.
     * Returned codes are plaintext and must be displayed exactly once; only
     * hashes are stored when the caller follows up with {@see enable()}.
     *
     * Throws if 2FA is already enabled — caller must {@see disable()} first.
     */
    public function setup(User $user): TwoFactorSetupResult
    {
        if ($this->isEnabled($user)) {
            throw new \RuntimeException('Two-factor authentication is already enabled for this user.');
        }

        $secret = $this->manager->generateSecret();
        $recoveryCodes = $this->manager->generateRecoveryCodes();
        $qrCodeUri = $this->manager->getQrCodeUri(
            $secret,
            $user->getEmail() !== '' ? $user->getEmail() : $user->getName(),
            self::ISSUER,
        );

        return new TwoFactorSetupResult(
            secret: $secret,
            qrCodeUri: $qrCodeUri,
            recoveryCodes: $recoveryCodes,
        );
    }

    /**
     * Persist a 2FA secret + recovery codes on the user. The caller must
     * prove possession of the secret by submitting a matching TOTP as
     * $firstCode. Returns true on success, false if $firstCode is invalid.
     *
     * @param list<string> $plaintextRecoveryCodes
     */
    public function enable(User $user, string $secret, array $plaintextRecoveryCodes, string $firstCode): bool
    {
        if (!$this->manager->verifyCode($secret, $firstCode)) {
            return false;
        }

        $hashes = array_map(
            static fn(string $code): string => password_hash($code, \PASSWORD_ARGON2ID),
            $plaintextRecoveryCodes,
        );

        $user->setTwoFactorSecret($secret);
        $user->setTwoFactorRecoveryCodesHash($hashes);
        $this->entityTypeManager->getRepository('user')->save($user);

        return true;
    }

    /**
     * Verify a TOTP code or a recovery code against the user's stored 2FA
     * material. TOTP is tried first; on miss, falls back to recovery codes.
     * A successful recovery match consumes that code (removes it from the
     * user's stored list) and persists the updated user.
     *
     * TOTP codes are single-use within their validity window: the time step
     * a code matches is recorded on the user, and any code whose matched
     * step is at or below the last recorded step is rejected as a replay.
     *
     * Returns false when 2FA is not enabled, the input is empty, the code is
     * a replay of an already-used TOTP step, or no stored code matches.
     */
    public function verify(User $user, string $code): bool
    {
        $secret = $user->getTwoFactorSecret();
        if ($secret === null || $code === '') {
            return false;
        }

        $matchedStep = $this->manager->verifyCodeStep($secret, $code);
        if ($matchedStep !== null) {
            $lastUsedStep = $user->getTwoFactorLastUsedStep();
            if ($lastUsedStep !== null && $matchedStep <= $lastUsedStep) {
                // Replay of a code from an already-consumed time step.
                return false;
            }

            $user->setTwoFactorLastUsedStep($matchedStep);
            $this->entityTypeManager->getRepository('user')->save($user);

            return true;
        }

        $hashes = $user->getTwoFactorRecoveryCodesHash() ?? [];
        foreach ($hashes as $index => $hash) {
            if (password_verify($code, $hash)) {
                unset($hashes[$index]);
                $user->setTwoFactorRecoveryCodesHash(array_values($hashes));
                $this->entityTypeManager->getRepository('user')->save($user);

                return true;
            }
        }

        return false;
    }

    /**
     * Atomically wipe the user's 2FA credentials. Caller is responsible for
     * requiring proof-of-possession before invoking this (see
     * DisableTwoFactorController for the route-level contract).
     */
    public function disable(User $user): void
    {
        $user->setTwoFactorSecret(null);
        $user->setTwoFactorRecoveryCodesHash(null);
        $this->entityTypeManager->getRepository('user')->save($user);
    }

    public function isEnabled(User $user): bool
    {
        return $user->getTwoFactorSecret() !== null;
    }
}
