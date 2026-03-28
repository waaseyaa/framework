<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

interface OAuthProviderInterface
{
    public function getName(): string;

    /**
     * @param array<string> $scopes
     */
    public function getAuthorizationUrl(array $scopes, string $state): string;

    public function exchangeCode(string $code): OAuthToken;

    /**
     * @throws UnsupportedOperationException
     */
    public function refreshToken(string $refreshToken): OAuthToken;

    public function getUserProfile(string $accessToken): OAuthUserProfile;
}
