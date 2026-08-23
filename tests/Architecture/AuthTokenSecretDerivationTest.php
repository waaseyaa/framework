<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AuthTokenSecretDerivationTest extends TestCase
{
    #[Test]
    public function the_auth_provider_does_not_coalesce_token_secrets_to_app_secret(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/packages/auth/src/AuthServiceProvider.php');
        self::assertStringNotContainsString('app_secret', $source);
        self::assertStringContainsString('AuthTokenSecret::resolve', $source);
        self::assertStringContainsString('PURPOSE_AUTH_TOKEN_HMAC', $source);
    }

    #[Test]
    public function repo_config_keeps_auth_token_secret_independent_of_the_master(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 2) . '/config/waaseyaa.php');
        self::assertMatchesRegularExpression(
            "/'token_secret'\\s*=>\\s*getenv\\('AUTH_TOKEN_SECRET'\\)\\s*\\?:\\s*''/",
            $config,
        );
    }
}
