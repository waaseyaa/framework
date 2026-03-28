<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Provider;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\OAuthProvider\OAuthException;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\UnsupportedOperationException;

final class GitHubOAuthProvider implements OAuthProviderInterface
{
    private const AUTH_ENDPOINT = 'https://github.com/login/oauth/authorize';
    private const TOKEN_ENDPOINT = 'https://github.com/login/oauth/access_token';
    private const USER_ENDPOINT = 'https://api.github.com/user';
    private const EMAILS_ENDPOINT = 'https://api.github.com/user/emails';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function getName(): string
    {
        return 'github';
    }

    public function getAuthorizationUrl(array $scopes, string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ];

        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    public function exchangeCode(string $code): OAuthToken
    {
        $response = $this->httpClient->post(
            self::TOKEN_ENDPOINT,
            ['Accept' => 'application/json'],
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
            ],
        );

        /** @var array{access_token?: string, token_type?: string, scope?: string, error?: string, error_description?: string} $data */
        $data = $response->json();

        if (isset($data['error'])) {
            throw new OAuthException($data['error_description'] ?? $data['error'], 'github', $response->statusCode);
        }

        return new OAuthToken(
            accessToken: $data['access_token'] ?? '',
            refreshToken: null,
            expiresAt: null,
            scopes: isset($data['scope']) ? explode(',', $data['scope']) : [],
            tokenType: 'Bearer',
        );
    }

    public function refreshToken(string $refreshToken): OAuthToken
    {
        throw new UnsupportedOperationException('github', 'token refresh');
    }

    public function getUserProfile(string $accessToken): OAuthUserProfile
    {
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ];

        $userResponse = $this->httpClient->get(self::USER_ENDPOINT, $headers);

        if (!$userResponse->isSuccess()) {
            /** @var array{message?: string} $errorData */
            $errorData = $userResponse->json();
            throw new OAuthException($errorData['message'] ?? 'Failed to fetch user profile', 'github', $userResponse->statusCode);
        }

        /** @var array{id: int|string, login?: string, name?: string, email?: string|null, avatar_url?: string|null} $userData */
        $userData = $userResponse->json();
        $email = $userData['email'] ?? null;

        if ($email === null) {
            $emailResponse = $this->httpClient->get(self::EMAILS_ENDPOINT, $headers);

            if ($emailResponse->isSuccess()) {
                /** @var list<array{email: string, primary: bool}> $emails */
                $emails = $emailResponse->json();
                foreach ($emails as $entry) {
                    if ($entry['primary']) {
                        $email = $entry['email'];
                        break;
                    }
                }
            }
        }

        return new OAuthUserProfile(
            providerId: (string) $userData['id'],
            email: $email ?? '',
            name: $userData['name'] ?? $userData['login'] ?? '',
            avatarUrl: $userData['avatar_url'] ?? null,
        );
    }
}
