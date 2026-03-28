<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\OAuthProvider\OAuthException;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\Provider\GoogleOAuthProvider;

#[CoversClass(GoogleOAuthProvider::class)]
final class GoogleOAuthProviderTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private const CLIENT_SECRET = 'test-client-secret';
    private const REDIRECT_URI = 'https://example.com/callback';

    public function testGetNameReturnsGoogle(): void
    {
        $this->assertSame('google', $this->createProvider()->getName());
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(OAuthProviderInterface::class, $this->createProvider());
    }

    public function testGetAuthorizationUrlContainsRequiredParams(): void
    {
        $url = $this->createProvider()->getAuthorizationUrl(['openid', 'email'], 'state123');

        $this->assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $url);
        $this->assertStringContainsString('client_id=' . self::CLIENT_ID, $url);
        $this->assertStringContainsString('redirect_uri=' . urlencode(self::REDIRECT_URI), $url);
        $this->assertStringContainsString('state=state123', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString(urlencode('openid email'), $url);
    }

    public function testExchangeCodeReturnsToken(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')->willReturn(new HttpResponse(
            statusCode: 200,
            body: json_encode([
                'access_token' => 'ya29.access',
                'refresh_token' => 'refresh123',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'token_type' => 'Bearer',
            ]),
        ));

        $token = $this->createProvider($httpClient)->exchangeCode('auth-code');

        $this->assertSame('ya29.access', $token->accessToken);
        $this->assertSame('refresh123', $token->refreshToken);
        $this->assertSame(['openid', 'email'], $token->scopes);
        $this->assertSame('Bearer', $token->tokenType);
        $this->assertNotNull($token->expiresAt);
    }

    public function testExchangeCodeThrowsOnError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')->willReturn(new HttpResponse(
            statusCode: 400,
            body: json_encode(['error' => 'invalid_grant', 'error_description' => 'Code expired']),
        ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Code expired');

        $this->createProvider($httpClient)->exchangeCode('expired-code');
    }

    public function testRefreshTokenReturnsNewToken(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')->willReturn(new HttpResponse(
            statusCode: 200,
            body: json_encode([
                'access_token' => 'ya29.refreshed',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'token_type' => 'Bearer',
            ]),
        ));

        $token = $this->createProvider($httpClient)->refreshToken('refresh123');

        $this->assertSame('ya29.refreshed', $token->accessToken);
        $this->assertNull($token->refreshToken);
    }

    public function testGetUserProfileReturnsProfile(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn(new HttpResponse(
            statusCode: 200,
            body: json_encode([
                'id' => '12345',
                'email' => 'user@gmail.com',
                'name' => 'Test User',
                'picture' => 'https://lh3.googleusercontent.com/photo.jpg',
            ]),
        ));

        $profile = $this->createProvider($httpClient)->getUserProfile('ya29.access');

        $this->assertSame('12345', $profile->providerId);
        $this->assertSame('user@gmail.com', $profile->email);
        $this->assertSame('Test User', $profile->name);
        $this->assertSame('https://lh3.googleusercontent.com/photo.jpg', $profile->avatarUrl);
    }

    public function testGetUserProfileThrowsOn401(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')->willReturn(new HttpResponse(
            statusCode: 401,
            body: json_encode(['error' => ['message' => 'Invalid token', 'code' => 401]]),
        ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Invalid token');

        $this->createProvider($httpClient)->getUserProfile('bad-token');
    }

    private function createProvider(?HttpClientInterface $httpClient = null): GoogleOAuthProvider
    {
        return new GoogleOAuthProvider(
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
            redirectUri: self::REDIRECT_URI,
            httpClient: $httpClient ?? $this->createStub(HttpClientInterface::class),
        );
    }
}
