<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Provider;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\UnsupportedOperationException;

/**
 * @api
 */
final class GitHubOAuthProvider implements OAuthProviderInterface
{
    private const AUTH_URL = 'https://github.com/login/oauth/authorize';
    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';
    private const USER_URL = 'https://api.github.com/user';
    private const EMAILS_URL = 'https://api.github.com/user/emails';

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

    /** @param list<string> $scopes */
    public function getAuthorizationUrl(array $scopes, string $state): string
    {
        $params = http_build_query([
            'client_id'    => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope'        => implode(' ', $scopes),
            'state'        => $state,
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
        ]);

        $response = $this->httpClient->post(self::TOKEN_URL, [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body);

        $data = $response->json();

        if (!$response->isSuccess()) {
            $message = $data['error_description'] ?? $data['error'] ?? 'Token request failed';
            throw new \RuntimeException((string) $message);
        }

        $scopes = [];
        if (isset($data['scope']) && $data['scope'] !== '') {
            $scopes = explode(',', (string) $data['scope']);
        }

        return new OAuthToken(
            accessToken: (string) $data['access_token'],
            refreshToken: null,
            expiresAt: null,
            scopes: $scopes,
        );
    }

    public function refreshToken(string $refreshToken): OAuthToken
    {
        throw UnsupportedOperationException::refreshNotSupported('github');
    }

    public function getUserProfile(string $accessToken): OAuthUserProfile
    {
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'Waaseyaa/1.0',
        ];

        $userResponse = $this->httpClient->get(self::USER_URL, $headers);
        $userData = $userResponse->json();

        // The user endpoint is the identity source. A 401/403/429/5xx returns an
        // error body with no `id`/`login`, which would otherwise be coerced into
        // a degenerate, empty-providerId profile (a wrong/empty identity). Fail
        // loudly instead — mirroring exchangeCode()'s isSuccess() check.
        if (!$userResponse->isSuccess()) {
            $message = $userData['message'] ?? 'GitHub user profile request failed';
            throw new \RuntimeException((string) $message);
        }
        if (!isset($userData['id']) || (string) $userData['id'] === '') {
            throw new \RuntimeException('GitHub user profile response is missing an account id.');
        }

        // Email is best-effort: a token without the `user:email` scope (or a
        // transient error on this secondary call) yields an unverified/empty
        // email, NOT a failed login. Only read the body on success so an error
        // payload is never mistaken for email data.
        $email = '';
        $emailVerified = false;
        $emailsResponse = $this->httpClient->get(self::EMAILS_URL, $headers);
        if ($emailsResponse->isSuccess()) {
            foreach ($emailsResponse->json() as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $primary = $entry['primary'] ?? false;
                $verified = $entry['verified'] ?? false;
                if ($primary === true && $verified === true && isset($entry['email'])) {
                    $email = (string) $entry['email'];
                    $emailVerified = true;
                    break;
                }
            }
        }

        $name = isset($userData['name']) && is_string($userData['name']) && $userData['name'] !== ''
            ? $userData['name']
            : (string) $userData['login'];

        return new OAuthUserProfile(
            providerId: (string) $userData['id'],
            email: $email,
            name: $name,
            avatarUrl: isset($userData['avatar_url']) ? (string) $userData['avatar_url'] : null,
            emailVerified: $emailVerified,
        );
    }
}
