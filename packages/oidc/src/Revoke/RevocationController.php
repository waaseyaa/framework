<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Revoke;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Oidc\ClientRegistry\OidcClientLookup;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;

/**
 * POST /oidc/revoke — token revocation per RFC 7009.
 *
 * Access-token revocation also cascades to the paired refresh token.
 * Refresh-token revocation does NOT cascade to the paired access token
 * (asymmetric per RFC 7009 §2.1 — only the access→refresh direction).
 *
 * Unknown tokens always return 200 to prevent token enumeration (RFC 7009 §2.2).
 *
 * @api
 */
final readonly class RevocationController
{
    public function __construct(
        private OidcClientLookup $clientLookup,
        private AccessTokenIssuer $accessTokenIssuer,
        private RefreshTokenIssuer $refreshTokenIssuer,
    ) {}

    public function __invoke(Request $request): Response
    {
        if ($request->getMethod() !== 'POST') {
            return $this->error(405, 'invalid_request', 'POST required.');
        }

        // Authenticate client
        [$credClientId, $credClientSecret] = $this->extractClientCredentials($request);

        if ($credClientId === null) {
            return $this->error(401, 'invalid_client', 'No client credentials provided.');
        }

        $client = $this->clientLookup->findByClientId($credClientId);
        if ($client === null) {
            return $this->error(401, 'invalid_client', 'Unknown client_id.');
        }

        if ($client->isConfidential()) {
            if ($credClientSecret === null) {
                return $this->error(401, 'invalid_client', 'Client authentication required.');
            }
            $hash = $client->getClientSecretHash();
            if ($hash === null || !password_verify($credClientSecret, $hash)) {
                return $this->error(401, 'invalid_client', 'Client authentication failed.');
            }
        }

        $token = $request->request->get('token');
        if (!is_string($token) || $token === '') {
            // RFC 7009 §2.1: missing token → 200 (no enumeration)
            return $this->ok();
        }

        $hint = $request->request->get('token_type_hint');
        $hint = is_string($hint) ? $hint : null;

        $now = new DateTimeImmutable();

        $this->revokeToken($token, $hint, $now);

        return $this->ok();
    }

    private function revokeToken(string $token, ?string $hint, DateTimeImmutable $now): void
    {
        if ($hint === 'refresh_token') {
            $this->tryRevokeRefresh($token, $now);

            return;
        }

        if ($hint === 'access_token') {
            $this->tryRevokeAccess($token, $now);

            return;
        }

        // No hint: try refresh first, then access
        if (!$this->tryRevokeRefresh($token, $now)) {
            $this->tryRevokeAccess($token, $now);
        }
    }

    private function tryRevokeRefresh(string $token, DateTimeImmutable $now): bool
    {
        $record = $this->refreshTokenIssuer->findByToken($token);
        if ($record === null) {
            return false;
        }

        $this->refreshTokenIssuer->revoke($record->jti, $now);

        // RFC 7009 §2.1: refresh revocation does NOT cascade to the access token
        return true;
    }

    private function tryRevokeAccess(string $tokenValue, DateTimeImmutable $now): bool
    {
        // The opaque access token value is stored in oidc_refresh_token.token? No —
        // access tokens are stored as opaque values in AccessTokenIssuer.
        // We need to look up by the opaque token value. Since AccessTokenIssuer
        // stores jti as PK but uses an opaque token value, we expose findByOpaqueToken.
        $row = $this->accessTokenIssuer->findByOpaqueToken($tokenValue);
        if ($row === null) {
            return false;
        }

        $jti = (string) $row['jti'];
        $this->accessTokenIssuer->revoke($jti, $now);

        // Cascade: revoke the paired refresh token
        $this->refreshTokenIssuer->revokeByAccessTokenJti($jti, $now);

        return true;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function extractClientCredentials(Request $request): array
    {
        $authHeader = $request->headers->get('Authorization');
        if (is_string($authHeader) && stripos($authHeader, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);
                if ($id !== '') {
                    return [$id, $secret !== '' ? $secret : null];
                }
            }
        }

        $clientId = $request->request->get('client_id');
        $clientSecret = $request->request->get('client_secret');

        if (is_string($clientId) && $clientId !== '') {
            return [$clientId, is_string($clientSecret) && $clientSecret !== '' ? $clientSecret : null];
        }

        return [null, null];
    }

    private function ok(): Response
    {
        $response = new JsonResponse(null, 200);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function error(int $status, string $error, string $description): Response
    {
        $response = new JsonResponse([
            'error' => $error,
            'error_description' => $description,
        ], $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
