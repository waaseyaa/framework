<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Provider;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\Provider\GoogleOAuthProvider;

final class GoogleOAuthProviderTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private GoogleOAuthProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->provider = new GoogleOAuthProvider(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            redirectUri: 'https://example.com/callback',
            httpClient: $this->httpClient,
        );
    }

    public function testGetName(): void
    {
        self::assertSame('google', $this->provider->getName());
    }

    public function testGetAuthorizationUrl(): void
    {
        $url = $this->provider->getAuthorizationUrl(['openid', 'email', 'profile'], 'random-state');

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        self::assertStringContainsString('client_id=test-client-id', $url);
        self::assertStringContainsString('redirect_uri=', $url);
        self::assertStringContainsString('response_type=code', $url);
        self::assertStringContainsString('state=random-state', $url);
        self::assertStringContainsString('access_type=offline', $url);
        self::assertStringContainsString('prompt=consent', $url);
        self::assertStringContainsString('scope=', $url);
    }

    public function testExchangeCode(): void
    {
        $responseBody = json_encode([
            'access_token'  => 'ya29.access_token',
            'refresh_token' => 'refresh-token-123',
            'expires_in'    => 3600,
            'scope'         => 'openid email profile',
            'token_type'    => 'Bearer',
        ]);

        $this->httpClient
            ->expects(self::once())
            ->method('post')
            ->with('https://oauth2.googleapis.com/token')
            ->willReturn(new HttpResponse(200, (string) $responseBody));

        $token = $this->provider->exchangeCode('auth-code-abc');

        self::assertInstanceOf(OAuthToken::class, $token);
        self::assertSame('ya29.access_token', $token->accessToken);
        self::assertSame('refresh-token-123', $token->refreshToken);
        self::assertNotNull($token->expiresAt);
        self::assertSame(['openid', 'email', 'profile'], $token->scopes);
    }

    public function testExchangeCodeThrowsOnHttpError(): void
    {
        $responseBody = json_encode([
            'error'             => 'invalid_grant',
            'error_description' => 'Code was already redeemed.',
        ]);

        $this->httpClient
            ->expects(self::once())
            ->method('post')
            ->willReturn(new HttpResponse(401, (string) $responseBody));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Code was already redeemed.');

        $this->provider->exchangeCode('bad-code');
    }

    public function testRefreshToken(): void
    {
        $responseBody = json_encode([
            'access_token' => 'ya29.new_access_token',
            'expires_in'   => 3600,
            'scope'        => 'openid email',
            'token_type'   => 'Bearer',
        ]);

        $this->httpClient
            ->expects(self::once())
            ->method('post')
            ->with('https://oauth2.googleapis.com/token')
            ->willReturn(new HttpResponse(200, (string) $responseBody));

        $token = $this->provider->refreshToken('old-refresh-token');

        self::assertInstanceOf(OAuthToken::class, $token);
        self::assertSame('ya29.new_access_token', $token->accessToken);
        self::assertNull($token->refreshToken);
    }

    public function testGetUserProfile(): void
    {
        $responseBody = json_encode([
            'id'             => '1234567890',
            'email'          => 'user@example.com',
            'verified_email' => true,
            'name'           => 'Test User',
            'picture'        => 'https://lh3.googleusercontent.com/photo.jpg',
        ]);

        $this->httpClient
            ->expects(self::once())
            ->method('get')
            ->with('https://www.googleapis.com/oauth2/v2/userinfo')
            ->willReturn(new HttpResponse(200, (string) $responseBody));

        $profile = $this->provider->getUserProfile('ya29.access_token');

        self::assertInstanceOf(OAuthUserProfile::class, $profile);
        self::assertSame('1234567890', $profile->providerId);
        self::assertSame('user@example.com', $profile->email);
        self::assertSame('Test User', $profile->name);
        self::assertSame('https://lh3.googleusercontent.com/photo.jpg', $profile->avatarUrl);
        self::assertTrue($profile->emailVerified);
    }
}
