<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;

#[CoversClass(AuthConfig::class)]
final class AuthConfigTest extends TestCase
{
    #[Test]
    public function defaults_registration_to_admin(): void
    {
        $config = AuthConfig::fromArray([]);

        self::assertSame('admin', $config->registration);
    }

    #[Test]
    public function defaults_require_verified_email_to_false(): void
    {
        $config = AuthConfig::fromArray([]);

        self::assertFalse($config->requireVerifiedEmail);
    }

    #[Test]
    public function resolves_mail_policy_to_dev_log_in_development(): void
    {
        $config = AuthConfig::fromArray([], 'local');

        self::assertSame(MailMissingPolicy::DevLog, $config->mailMissingPolicy);
    }

    #[Test]
    public function resolves_mail_policy_to_fail_in_production(): void
    {
        $config = AuthConfig::fromArray([], 'production');

        self::assertSame(MailMissingPolicy::Fail, $config->mailMissingPolicy);
    }

    #[Test]
    public function explicit_mail_policy_overrides_auto(): void
    {
        $config = AuthConfig::fromArray(['mail_missing_policy' => 'silent'], 'production');

        self::assertSame(MailMissingPolicy::Silent, $config->mailMissingPolicy);
    }

    #[Test]
    public function reads_token_ttl_defaults(): void
    {
        $config = AuthConfig::fromArray([]);

        self::assertSame(3600, $config->tokenTtl('password_reset'));
        self::assertSame(86400, $config->tokenTtl('email_verification'));
        self::assertSame(604800, $config->tokenTtl('invite'));
    }

    #[Test]
    public function reads_custom_token_ttl(): void
    {
        $config = AuthConfig::fromArray([
            'token_ttls' => ['password_reset' => 7200],
        ]);

        self::assertSame(7200, $config->tokenTtl('password_reset'));
        self::assertSame(86400, $config->tokenTtl('email_verification'));
        self::assertSame(604800, $config->tokenTtl('invite'));
    }

    #[Test]
    public function reads_token_secret(): void
    {
        $config = AuthConfig::fromArray(['token_secret' => 'my-secret-key']);

        self::assertSame('my-secret-key', $config->tokenSecret);
    }

    #[Test]
    public function parses_registration_modes(): void
    {
        self::assertSame('open', AuthConfig::fromArray(['registration' => 'open'])->registration);
        self::assertSame('invite', AuthConfig::fromArray(['registration' => 'invite'])->registration);
        self::assertSame('admin', AuthConfig::fromArray(['registration' => 'admin'])->registration);
        self::assertSame('admin', AuthConfig::fromArray(['registration' => 'invalid'])->registration);
    }
}
