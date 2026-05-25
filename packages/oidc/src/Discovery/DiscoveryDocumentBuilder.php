<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Discovery;

/**
 * Builds the OIDC Discovery document per OpenID Connect Discovery 1.0.
 *
 * Pure function — no side effects. Used by DiscoveryController and tests.
 *
 * @api
 */
final class DiscoveryDocumentBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $issuer): array
    {
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oidc/authorize',
            'token_endpoint' => $issuer . '/oidc/token',
            'userinfo_endpoint' => $issuer . '/oidc/userinfo',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'revocation_endpoint' => $issuer . '/oidc/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'name', 'preferred_username', 'email', 'email_verified'],
            'code_challenge_methods_supported' => ['S256'],
        ];
    }
}
