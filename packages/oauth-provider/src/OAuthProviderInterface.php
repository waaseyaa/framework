<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

interface OAuthProviderInterface
{
    public function getName(): string;

    /** @param list<string> $scopes */
    public function getAuthorizationUrl(array $scopes, string $state): string;

    public function exchangeCode(string $code): OAuthToken;

    /** @throws UnsupportedOperationException if the provider does not support token refresh */
    public function refreshToken(string $refreshToken): OAuthToken;

    public function getUserProfile(string $accessToken): OAuthUserProfile;
}
