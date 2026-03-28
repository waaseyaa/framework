<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Provider;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\Provider\GitHubOAuthProvider;
use Waaseyaa\OAuthProvider\UnsupportedOperationException;

final class GitHubOAuthProviderTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private GitHubOAuthProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->provider = new GitHubOAuthProvider(
            clientId: 'gh-client-id',
            clientSecret: 'gh-client-secret',
            redirectUri: 'https://example.com/callback',
            httpClient: $this->httpClient,
        );
    }

    public function testGetName(): void
    {
        self::assertSame('github', $this->provider->getName());
    }

    public function testGetAuthorizationUrl(): void
    {
        $url = $this->provider->getAuthorizationUrl(['repo', 'user:email'], 'some-state');

        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        self::assertStringContainsString('client_id=gh-client-id', $url);
        self::assertStringContainsString('redirect_uri=', $url);
        self::assertStringContainsString('state=some-state', $url);
        self::assertStringContainsString('scope=', $url);
    }

    public function testExchangeCode(): void
    {
        $responseBody = json_encode([
            'access_token' => 'gho_abc123',
            'scope'        => 'repo,user:email',
            'token_type'   => 'bearer',
        ]);

        $this->httpClient
            ->expects(self::once())
            ->method('post')
            ->with('https://github.com/login/oauth/access_token')
            ->willReturn(new HttpResponse(200, (string) $responseBody));

        $token = $this->provider->exchangeCode('github-code-xyz');

        self::assertInstanceOf(OAuthToken::class, $token);
        self::assertSame('gho_abc123', $token->accessToken);
        self::assertNull($token->refreshToken);
        self::assertNull($token->expiresAt);
        self::assertSame(['repo', 'user:email'], $token->scopes);
    }

    public function testRefreshTokenThrowsUnsupportedException(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage("'github'");

        $this->provider->refreshToken('some-token');
    }

    public function testGetUserProfile(): void
    {
        $userBody = json_encode([
            'id'         => 42,
            'login'      => 'jonesrussell',
            'name'       => 'Russell Jones',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/42',
        ]);

        $emailsBody = json_encode([
            ['email' => 'secondary@example.com', 'primary' => false, 'verified' => true],
            ['email' => 'jonesrussell42@gmail.com', 'primary' => true, 'verified' => true],
        ]);

        $this->httpClient
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, (string) $userBody),
                new HttpResponse(200, (string) $emailsBody),
            );

        $profile = $this->provider->getUserProfile('gho_abc123');

        self::assertInstanceOf(OAuthUserProfile::class, $profile);
        self::assertSame('42', $profile->providerId);
        self::assertSame('jonesrussell42@gmail.com', $profile->email);
        self::assertSame('Russell Jones', $profile->name);
        self::assertSame('https://avatars.githubusercontent.com/u/42', $profile->avatarUrl);
    }

    public function testGetUserProfileFallsBackToLoginWhenNameIsNull(): void
    {
        $userBody = json_encode([
            'id'         => 99,
            'login'      => 'ghostuser',
            'name'       => null,
            'avatar_url' => null,
        ]);

        $emailsBody = json_encode([
            ['email' => 'ghost@example.com', 'primary' => true, 'verified' => true],
        ]);

        $this->httpClient
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, (string) $userBody),
                new HttpResponse(200, (string) $emailsBody),
            );

        $profile = $this->provider->getUserProfile('gho_ghost');

        self::assertSame('ghostuser', $profile->name);
        self::assertNull($profile->avatarUrl);
    }
}
