<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Provider;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;

final class GoogleOAuthProvider implements OAuthProviderInterface
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function getName(): string
    {
        return 'google';
    }

    /** @param list<string> $scopes */
    public function getAuthorizationUrl(array $scopes, string $state): string
    {
        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $scopes),
            'state'         => $state,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);

        return self::AUTH_URL . '?' . $params;
    }

    public function exchangeCode(string $code): OAuthToken
    {
        $body = http_build_query([
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        return $this->requestToken($body);
    }

    public function refreshToken(string $refreshToken): OAuthToken
    {
        $body = http_build_query([
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'refresh_token',
        ]);

        return $this->requestToken($body);
    }

    public function getUserProfile(string $accessToken): OAuthUserProfile
    {
        $response = $this->httpClient->get(self::USERINFO_URL, [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        $data = $response->json();

        return new OAuthUserProfile(
            providerId: (string) $data['id'],
            email: (string) $data['email'],
            name: (string) $data['name'],
            avatarUrl: isset($data['picture']) ? (string) $data['picture'] : null,
        );
    }

    private function requestToken(string $body): OAuthToken
    {
        $response = $this->httpClient->post(self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body);

        $data = $response->json();

        if (!$response->isSuccess()) {
            $message = $data['error_description'] ?? $data['error'] ?? 'Token request failed';
            throw new \RuntimeException((string) $message);
        }

        $expiresAt = null;
        if (isset($data['expires_in'])) {
            $expiresAt = new \DateTimeImmutable('+' . (int) $data['expires_in'] . ' seconds');
        }

        $scopes = [];
        if (isset($data['scope']) && $data['scope'] !== '') {
            $scopes = explode(' ', (string) $data['scope']);
        }

        return new OAuthToken(
            accessToken: (string) $data['access_token'],
            refreshToken: isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            expiresAt: $expiresAt,
            scopes: $scopes,
        );
    }
}
