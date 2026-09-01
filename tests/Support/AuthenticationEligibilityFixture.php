<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\User\Authentication\AuthenticationEligibilityInterface;

/** Test-only construction of the production authentication policy. */
final class AuthenticationEligibilityFixture
{
    public static function policy(bool $requireVerifiedEmail = false): AuthenticationEligibilityInterface
    {
        return new VerifiedEmailAuthenticationEligibility(
            new AuthConfig('open', $requireVerifiedEmail, MailMissingPolicy::DevLog, 'test-secret', []),
            new UserInternalFieldReaderFixture(),
        );
    }
}
