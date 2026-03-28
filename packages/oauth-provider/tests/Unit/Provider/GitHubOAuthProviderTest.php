<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\OAuthProvider\OAuthException;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\Provider\GitHubOAuthProvider;
use Waaseyaa\OAuthProvider\UnsupportedOperationException;

#[CoversClass(GitHubOAuthProvider::class)]
final class GitHubOAuthProviderTest extends TestCase
{
    private const CLIENT_ID = 'gh-client-id';
    private const CLIENT_SECRET = 'gh-client-secret';
    private const REDIRECT_URI = 'https://example.com/github/callback';

    public function testGetNameReturnsGithub(): void
    {
        $this->assertSame('github', $this->createProvider()->getName());
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(OAuthProviderInterface::class, $this->createProvider());
    }

    public function testGetAuthorizationUrlContainsRequiredParams(): void
    {
        $url = $this->createProvider()->getAuthorizationUrl(['user:email', 'read:user'], 'state456');

        $this->assertStringContainsString('github.com/login/oauth/authorize', $url);
        $this->assertStringContainsString('client_id=' . self::CLIENT_ID, $url);
        $this->assertStringContainsString('redirect_uri=' . urlencode(self::REDIRECT_URI), $url);
        $this->assertStringContainsString('state=state456', $url);
        $this->assertStringContainsString(urlencode('user:email read:user'), $url);
    }

    public function testExchangeCodeReturnsToken(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')->willReturn(new HttpResponse(
            statusCode: 200,
            body: json_encode([
                'access_token' => 'gho_abc123',
                'token_type' => 'bearer',
                'scope' => 'user:email,read:user',
            ]),
        ));

        $token = $this->createProvider($httpClient)->exchangeCode('gh-auth-code');

        $this->assertSame('gho_abc123', $token->accessToken);
        $this->assertNull($token->refreshToken);
        $this->assertNull($token->expiresAt);
        $this->assertSame(['user:email', 'read:user'], $token->scopes);
    }

    public function testExchangeCodeThrowsOnError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')->willReturn(new HttpResponse(
            statusCode: 200,
            body: json_encode(['error' => 'bad_verification_code', 'error_description' => 'The code passed is incorrect or expired.']),
        ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('The code passed is incorrect or expired.');

        $this->createProvider($httpClient)->exchangeCode('bad-code');
    }

    public function testRefreshTokenThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('Provider "github" does not support token refresh');

        $this->createProvider()->refreshToken('anything');
    }

    public function testGetUserProfileReturnsProfile(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturnCallback(function (string $url) {
            if (str_contains($url, '/user/emails')) {
                return new HttpResponse(
                    statusCode: 200,
                    body: json_encode([
                        ['email' => 'secondary@example.com', 'primary' => false],
                        ['email' => 'primary@example.com', 'primary' => true],
                    ]),
                );
            }

            return new HttpResponse(
                statusCode: 200,
                body: json_encode([
                    'id' => 98765,
                    'login' => 'testuser',
                    'name' => 'Test User',
                    'avatar_url' => 'https://avatars.githubusercontent.com/u/98765',
                    'email' => null,
                ]),
            );
        });

        $profile = $this->createProvider($httpClient)->getUserProfile('gho_abc123');

        $this->assertSame('98765', $profile->providerId);
        $this->assertSame('primary@example.com', $profile->email);
        $this->assertSame('Test User', $profile->name);
        $this->assertSame('https://avatars.githubusercontent.com/u/98765', $profile->avatarUrl);
    }

    public function testGetUserProfileUsesInlineEmailWhenAvailable(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturnCallback(function (string $url) {
            if (str_contains($url, '/user/emails')) {
                return new HttpResponse(statusCode: 200, body: '[]');
            }

            return new HttpResponse(
                statusCode: 200,
                body: json_encode([
                    'id' => 11111,
                    'login' => 'hasmail',
                    'name' => 'Has Email',
                    'avatar_url' => 'https://avatars.githubusercontent.com/u/11111',
                    'email' => 'inline@example.com',
                ]),
            );
        });

        $profile = $this->createProvider($httpClient)->getUserProfile('gho_token');

        $this->assertSame('inline@example.com', $profile->email);
    }

    private function createProvider(?HttpClientInterface $httpClient = null): GitHubOAuthProvider
    {
        return new GitHubOAuthProvider(
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
            redirectUri: self::REDIRECT_URI,
            httpClient: $httpClient ?? $this->createStub(HttpClientInterface::class),
        );
    }
}
