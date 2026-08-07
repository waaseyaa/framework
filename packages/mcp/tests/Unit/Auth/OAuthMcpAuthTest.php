<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Mcp\Auth\OAuthAccessTokenValidatorInterface;
use Waaseyaa\Mcp\Auth\OAuthMcpAuth;
use Waaseyaa\Mcp\Auth\OAuthProtectedResourceMetadataConfig;
use Waaseyaa\Mcp\Auth\ScopedPrincipal;

#[CoversClass(OAuthMcpAuth::class)]
final class OAuthMcpAuthTest extends TestCase
{
    #[Test]
    public function it_delegates_a_bearer_token_with_the_exact_resource_audience(): void
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(42);
        $validator = new class ($account) implements OAuthAccessTokenValidatorInterface {
            public string $token = '';
            public string $resource = '';

            public function __construct(private AuthorizationPrincipalInterface $account) {}

            public function validate(string $accessToken, string $resource): ?ScopedPrincipal
            {
                $this->token = $accessToken;
                $this->resource = $resource;

                return new ScopedPrincipal($this->account, ['content.write']);
            }
        };
        $auth = new OAuthMcpAuth($validator, $this->resource());

        $principal = $auth->authenticateWithScopes('Bearer secret-token');

        self::assertSame($account, $principal?->account);
        self::assertSame(['content.write'], $principal?->scopes);
        self::assertSame('secret-token', $validator->token);
        self::assertSame('https://cms.example/mcp/write', $validator->resource);
    }

    #[Test]
    public function malformed_empty_and_scopeless_tokens_fail_closed(): void
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $validator = new class ($account) implements OAuthAccessTokenValidatorInterface {
            public function __construct(private AuthorizationPrincipalInterface $account) {}

            public function validate(string $accessToken, string $resource): ?ScopedPrincipal
            {
                return new ScopedPrincipal($this->account, []);
            }
        };
        $auth = new OAuthMcpAuth($validator, $this->resource());

        self::assertNull($auth->authenticateWithScopes(null));
        self::assertNull($auth->authenticateWithScopes('Basic abc'));
        self::assertNull($auth->authenticateWithScopes('Bearer   '));
        self::assertNull($auth->authenticateWithScopes('Bearer token'));
    }

    private function resource(): OAuthProtectedResourceMetadataConfig
    {
        return new OAuthProtectedResourceMetadataConfig(
            'https://cms.example/mcp/write',
            ['https://identity.example'],
        );
    }
}
