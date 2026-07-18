<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Authorize;

use Waaseyaa\Oidc\ClientRegistry\OidcClientSystemReader;
use Waaseyaa\Oidc\Entity\OidcClient;

/**
 * Validates a parsed /authorize query against a registered OIDC client.
 *
 * Rejection order matters: client_id and redirect_uri must pass first, because
 * they determine whether subsequent errors can redirect back to the relying
 * party or must render a direct error page. See OAuth 2.0 §4.1.2.1 and
 * ADR-006 §2.5 (PKCE S256 required).
 */
final class AuthorizationRequestValidator
{
    private const REQUIRED_SCOPE = 'openid';
    private const REQUIRED_RESPONSE_TYPE = 'code';
    private const REQUIRED_CODE_CHALLENGE_METHOD = 'S256';

    private readonly OidcClientSystemReader $clientReader;

    public function __construct(?OidcClientSystemReader $clientReader = null)
    {
        $this->clientReader = $clientReader ?? new OidcClientSystemReader();
    }

    /**
     * @param array<string, mixed> $query
     */
    public function validate(OidcClient $client, array $query): ValidatedAuthorizationRequest
    {
        $redirectUri = $this->stringOrNull($query, 'redirect_uri');
        if ($redirectUri === null) {
            throw new AuthorizationRequestException(
                errorCode: 'invalid_request',
                errorDescription: 'Missing redirect_uri.',
            );
        }

        $registration = $this->clientReader->registration($client);
        if (!$registration->hasRedirectUri($redirectUri)) {
            throw new AuthorizationRequestException(
                errorCode: 'invalid_request',
                errorDescription: 'redirect_uri is not registered for this client.',
            );
        }

        $state = $this->stringOrNull($query, 'state');

        $responseType = $this->stringOrNull($query, 'response_type');
        if ($responseType !== self::REQUIRED_RESPONSE_TYPE) {
            throw new AuthorizationRequestException(
                errorCode: 'unsupported_response_type',
                errorDescription: 'Only response_type=code is supported.',
                redirectUri: $redirectUri,
                state: $state,
            );
        }

        $scopes = $this->parseScopes($this->stringOrNull($query, 'scope'));
        if (!\in_array(self::REQUIRED_SCOPE, $scopes, true)) {
            throw new AuthorizationRequestException(
                errorCode: 'invalid_scope',
                errorDescription: "Scope must include '" . self::REQUIRED_SCOPE . "'.",
                redirectUri: $redirectUri,
                state: $state,
            );
        }

        foreach ($scopes as $scope) {
            if (!$registration->hasScope($scope)) {
                throw new AuthorizationRequestException(
                    errorCode: 'invalid_scope',
                    errorDescription: "Scope '$scope' is not allowed for this client.",
                    redirectUri: $redirectUri,
                    state: $state,
                );
            }
        }

        $codeChallenge = $this->stringOrNull($query, 'code_challenge');
        if ($codeChallenge === null) {
            throw new AuthorizationRequestException(
                errorCode: 'invalid_request',
                errorDescription: 'Missing code_challenge. PKCE is required.',
                redirectUri: $redirectUri,
                state: $state,
            );
        }

        $codeChallengeMethod = $this->stringOrNull($query, 'code_challenge_method');
        if ($codeChallengeMethod !== self::REQUIRED_CODE_CHALLENGE_METHOD) {
            throw new AuthorizationRequestException(
                errorCode: 'invalid_request',
                errorDescription: 'code_challenge_method must be S256.',
                redirectUri: $redirectUri,
                state: $state,
            );
        }

        return new ValidatedAuthorizationRequest(
            client: $client,
            redirectUri: $redirectUri,
            scopes: $scopes,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
            state: $state,
            nonce: $this->stringOrNull($query, 'nonce'),
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function stringOrNull(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return string[]
     */
    private function parseScopes(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $parts = preg_split('/\s+/', trim($raw));
        if ($parts === false) {
            return [];
        }

        return array_values(array_filter($parts, static fn(string $s): bool => $s !== ''));
    }
}
