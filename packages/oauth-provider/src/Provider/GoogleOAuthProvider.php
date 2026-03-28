<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Provider;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\OAuthProvider\OAuthException;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;

final class GoogleOAuthProvider implements OAuthProviderInterface
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

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

    /**
     * @param array<string> $scopes
     */
    public function getAuthorizationUrl(array $scopes, string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    public function exchangeCode(string $code): OAuthToken
    {
        $response = $this->httpClient->post(self::TOKEN_ENDPOINT, [], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
        ]);

        $data = $this->parseResponse($response);

        return $this->buildToken($data, hasRefreshToken: true);
    }

    public function refreshToken(string $refreshToken): OAuthToken
    {
        $response = $this->httpClient->post(self::TOKEN_ENDPOINT, [], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $data = $this->parseResponse($response);

        return $this->buildToken($data, hasRefreshToken: false);
    }

    public function getUserProfile(string $accessToken): OAuthUserProfile
    {
        $response = $this->httpClient->get(
            self::USERINFO_ENDPOINT,
            ['Authorization' => 'Bearer ' . $accessToken],
        );

        $data = $this->parseResponse($response);

        $rawId = $data['id'] ?? '';
        $providerId = is_string($rawId) || is_int($rawId) ? (string) $rawId : '';
        $email = is_string($data['email'] ?? null) ? $data['email'] : '';
        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $avatarUrl = is_string($data['picture'] ?? null) ? $data['picture'] : null;

        return new OAuthUserProfile(
            providerId: $providerId,
            email: $email,
            name: $name,
            avatarUrl: $avatarUrl,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildToken(array $data, bool $hasRefreshToken): OAuthToken
    {
        $accessToken = is_string($data['access_token'] ?? null) ? $data['access_token'] : '';
        $refreshToken = $hasRefreshToken && is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null;
        $scope = is_string($data['scope'] ?? null) ? $data['scope'] : '';
        $tokenType = is_string($data['token_type'] ?? null) ? $data['token_type'] : 'Bearer';
        $rawExpiresIn = $data['expires_in'] ?? null;
        $expiresIn = is_int($rawExpiresIn) || is_string($rawExpiresIn) ? (int) $rawExpiresIn : null;

        return new OAuthToken(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresIn !== null
                ? new \DateTimeImmutable('+' . $expiresIn . ' seconds')
                : null,
            scopes: $scope !== '' ? explode(' ', $scope) : [],
            tokenType: $tokenType,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(HttpResponse $response): array
    {
        $data = $response->json();

        if (!$response->isSuccess()) {
            $errorDescription = is_string($data['error_description'] ?? null) ? $data['error_description'] : null;
            $error = $data['error'] ?? null;
            $nestedMessage = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null;
            $topLevelError = is_string($error) ? $error : null;

            $message = $errorDescription ?? $nestedMessage ?? $topLevelError ?? 'Unknown error';

            throw new OAuthException($message, 'google', $response->statusCode);
        }

        return $data;
    }
}
