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
    private HttpClientInterface $httpClient;
    private GitHubOAuthProvider $provider;

    protected function setUp(): void
    {
        $this->useHttpClient($this->createStub(HttpClientInterface::class));
    }

    private function useHttpClient(HttpClientInterface $httpClient, bool $fetchEmail = true): void
    {
        $this->httpClient = $httpClient;
        $this->provider = new GitHubOAuthProvider(
            clientId: 'gh-client-id',
            clientSecret: 'gh-client-secret',
            redirectUri: 'https://example.com/callback',
            httpClient: $this->httpClient,
            fetchEmail: $fetchEmail,
        );
    }

    /** @return HttpClientInterface&MockObject */
    private function mockHttpClient(): HttpClientInterface
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->useHttpClient($httpClient);

        return $httpClient;
    }

    /** @return HttpClientInterface&MockObject */
    private function mockIdentityOnlyHttpClient(): HttpClientInterface
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->useHttpClient($httpClient, fetchEmail: false);

        return $httpClient;
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

        $this->mockHttpClient()
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

        $this->mockHttpClient()
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
        self::assertTrue($profile->emailVerified);
    }

    public function testGetUserProfileThrowsOnErrorResponseInsteadOfDegenerateIdentity(): void
    {
        // A 401 from the user endpoint returns an error body with no id/login.
        // The pre-fix code coerced this into a profile with an empty providerId
        // (a wrong/empty identity); it must now fail loudly.
        $errorBody = json_encode(['message' => 'Bad credentials']);

        $this->httpClient
            ->method('get')
            ->willReturn(new HttpResponse(401, (string) $errorBody));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bad credentials');

        $this->provider->getUserProfile('gho_revoked');
    }

    public function testGetUserProfileToleratesFailedEmailsLookup(): void
    {
        // A token without the user:email scope: the user call succeeds, the
        // emails call 403s. The login must still succeed, just without a
        // verified email — the error body must not be read as email data.
        $userBody = json_encode(['id' => 7, 'login' => 'noemail', 'name' => 'No Email']);
        $emailsErrorBody = json_encode(['message' => 'Requires user:email scope']);

        $this->mockHttpClient()
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, (string) $userBody),
                new HttpResponse(403, (string) $emailsErrorBody),
            );

        $profile = $this->provider->getUserProfile('gho_noemail');

        self::assertSame('7', $profile->providerId);
        self::assertSame('', $profile->email);
        self::assertFalse($profile->emailVerified);
    }

    public function testGetUserProfileIdentityOnlySkipsEmailsCall(): void
    {
        // fetchEmail: false must make exactly one GET call (/user) and never
        // call /user/emails at all — not even a call whose result is ignored.
        $userBody = json_encode([
            'id'         => 42,
            'login'      => 'jonesrussell',
            'name'       => 'Russell Jones',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/42',
        ]);

        $this->mockIdentityOnlyHttpClient()
            ->expects(self::once())
            ->method('get')
            ->with('https://api.github.com/user')
            ->willReturn(new HttpResponse(200, (string) $userBody));

        $profile = $this->provider->getUserProfile('gho_abc123');

        self::assertInstanceOf(OAuthUserProfile::class, $profile);
        self::assertSame('42', $profile->providerId);
        self::assertSame('Russell Jones', $profile->name);
        self::assertSame('', $profile->email);
        self::assertFalse($profile->emailVerified);
    }

    public function testGetUserProfileIdentityOnlyFallsBackToLoginWhenNameMissing(): void
    {
        // Optional absent fields (name here) must not warn or block a valid,
        // stable identity-only profile.
        $userBody = json_encode([
            'id'    => 99,
            'login' => 'ghostuser',
        ]);

        $this->mockIdentityOnlyHttpClient()
            ->expects(self::once())
            ->method('get')
            ->willReturn(new HttpResponse(200, (string) $userBody));

        $profile = $this->provider->getUserProfile('gho_ghost');

        self::assertSame('99', $profile->providerId);
        self::assertSame('ghostuser', $profile->name);
        self::assertNull($profile->avatarUrl);
        self::assertSame('', $profile->email);
        self::assertFalse($profile->emailVerified);
    }

    public function testGetUserProfileFallsBackToEmptyNameWhenNameAndLoginBothMissing(): void
    {
        // A malformed/incomplete upstream response missing both name and
        // login must not warn (failOnWarning=true would fail this test) and
        // must not block the stable numeric id.
        $userBody = json_encode(['id' => 99]);

        $this->mockIdentityOnlyHttpClient()
            ->expects(self::once())
            ->method('get')
            ->with('https://api.github.com/user')
            ->willReturn(new HttpResponse(200, (string) $userBody));

        $profile = $this->provider->getUserProfile('gho_incomplete');

        self::assertSame('99', $profile->providerId);
        self::assertSame('', $profile->name);
    }

    public function testGetUserProfileFallsBackToEmptyNameWhenNameAndLoginAreNonScalar(): void
    {
        // Malformed upstream values (name/login as arrays) must not trigger
        // an "Array to string conversion" warning or coerce into a nonsense
        // string.
        $userBody = json_encode(['id' => 99, 'name' => ['nested'], 'login' => ['nested']]);

        $this->mockIdentityOnlyHttpClient()
            ->expects(self::once())
            ->method('get')
            ->with('https://api.github.com/user')
            ->willReturn(new HttpResponse(200, (string) $userBody));

        $profile = $this->provider->getUserProfile('gho_malformed');

        self::assertSame('99', $profile->providerId);
        self::assertSame('', $profile->name);
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

        $this->mockHttpClient()
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
