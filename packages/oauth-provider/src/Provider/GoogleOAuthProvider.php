<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Provider;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;

/**
 * @api
 */
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
        private readonly GoogleAccessType $accessType = GoogleAccessType::Offline,
        private readonly bool $forceConsent = true,
    ) {}

    public function getName(): string
    {
        return 'google';
    }

    /** @param list<string> $scopes */
    public function getAuthorizationUrl(array $scopes, string $state): string
    {
        $params = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $scopes),
            'state'         => $state,
            'access_type'   => $this->accessType->value,
        ];

        if ($this->forceConsent) {
            $params['prompt'] = 'consent';
        }

        return self::AUTH_URL . '?' . http_build_query($params);
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

        // The userinfo endpoint is the identity source. A 401/403/5xx returns an
        // error body with no `id`/`email`, which would otherwise be coerced into
        // a degenerate, empty-providerId profile (a wrong/empty identity). Fail
        // loudly instead — mirroring the hardened GitHub provider's isSuccess() check.
        if (!$response->isSuccess()) {
            $message = $data['error_description'] ?? $data['error'] ?? 'Google user profile request failed';
            throw new \RuntimeException((string) $message);
        }
        if (!isset($data['id']) || (string) $data['id'] === '') {
            throw new \RuntimeException('Google user profile response is missing an account id.');
        }

        // email/name are optional profile fields. Absent, null, or malformed
        // (non-string) values must not warn or block the stable id above.
        $email = isset($data['email']) && is_string($data['email']) ? $data['email'] : '';
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';

        return new OAuthUserProfile(
            providerId: (string) $data['id'],
            email: $email,
            name: $name,
            avatarUrl: isset($data['picture']) ? (string) $data['picture'] : null,
            emailVerified: ($data['verified_email'] ?? false) === true,
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
