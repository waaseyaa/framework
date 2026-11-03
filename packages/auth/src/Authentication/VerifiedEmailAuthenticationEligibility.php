<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Authentication;

use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\User\Authentication\AuthenticationEligibilityInterface;
use Waaseyaa\User\Authentication\AuthenticationStage;
use Waaseyaa\User\User;

/** Canonical active-account and verified-email authentication policy. @api */
final readonly class VerifiedEmailAuthenticationEligibility implements AuthenticationEligibilityInterface
{
    public function __construct(
        private AuthConfig $config,
        private UserInternalFieldReaderInterface $internalFields,
    ) {}

    public function allows(User $user, AuthenticationStage $stage): bool
    {
        $verification = $this->internalFields->verification($user);
        if (!$verification->active) {
            return false;
        }

        if ($this->config->requireLegacyPasswordChange) {
            $credentials = $this->internalFields->credentials($user);
            if ($credentials->passwordHash === '' && ($credentials->legacyPasswordHash ?? '') !== '') {
                return false;
            }
        }

        return !$this->config->requireVerifiedEmail
            || $verification->emailVerified;
    }
}
