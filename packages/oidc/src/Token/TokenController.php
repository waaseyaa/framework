<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use Closure;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Oidc\ClientRegistry\OidcClientLookup;
use Waaseyaa\Oidc\Repository\AuthorizationCodeRepositoryInterface;

/**
 * OAuth 2.1 / OIDC token endpoint (ADR-006 §7, OIDC Core §3.1.3).
 *
 * Dispatches grant_type=authorization_code to the auth-code path,
 * grant_type=refresh_token to RefreshTokenGrantHandler (WP02).
 * Enforces PKCE S256 (ADR-006 §2.5), byte-exact redirect_uri match,
 * and client_id binding. Confidential clients authenticate via HTTP Basic
 * or client_secret_post (RFC 6749 §2.3.1); public clients rely on PKCE alone.
 *
 * Token response includes refresh_token per RFC 6749 §4.1.4.
 */
final readonly class TokenController
{
    private const ACCESS_TOKEN_EXPIRY = 3600;

    /**
     * @param Closure(): DateTimeImmutable $clock
     */
    public function __construct(
        private OidcClientLookup $clientLookup,
        private TokenRequestValidator $validator,
        private PkceVerifier $pkceVerifier,
        private AuthorizationCodeRepositoryInterface $codeRepository,
        private IdTokenMinter $idTokenMinter,
        private AccessTokenIssuer $accessTokenIssuer,
        private RefreshTokenIssuer $refreshTokenIssuer,
        private RefreshTokenGrantHandler $refreshGrantHandler,
        private string $issuer,
        private Closure $clock,
    ) {}

    public function __invoke(Request $request): Response
    {
        if ($request->getMethod() !== 'POST') {
            return $this->error(405, 'invalid_request', 'POST required.');
        }

        $form = $request->request->all();
        $grantType = is_string($form['grant_type'] ?? null) ? $form['grant_type'] : '';

        if ($grantType === 'refresh_token') {
            return $this->handleRefreshGrant($request, $form);
        }

        try {
            $tokenRequest = $this->validator->validate($form);
        } catch (TokenRequestException $e) {
            return $this->error(400, $e->errorCode, $e->errorDescription);
        }

        [$credClientId, $credClientSecret] = $this->extractClientCredentials($request, $tokenRequest);
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

        $stored = $this->codeRepository->consume($tokenRequest->code);
        if ($stored === null) {
            return $this->error(400, 'invalid_grant', 'Authorization code is invalid or expired.');
        }

        if ($stored->clientId !== $client->getClientId()) {
            return $this->error(400, 'invalid_grant', 'Authorization code was not issued to this client.');
        }

        if ($stored->redirectUri !== $tokenRequest->redirectUri) {
            return $this->error(400, 'invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        if (!$this->pkceVerifier->verify(
            $tokenRequest->codeVerifier,
            $stored->codeChallenge,
            $stored->codeChallengeMethod,
        )) {
            return $this->error(400, 'invalid_grant', 'PKCE verifier does not match the stored code_challenge.');
        }

        $now = ($this->clock)();

        $idToken = $this->idTokenMinter->mint(
            issuer: $this->issuer,
            subject: $stored->accountId,
            audience: $client->getClientId(),
            nonce: $stored->nonce,
            now: $now,
        );

        $accessTokenPair = $this->accessTokenIssuer->issue(
            clientId: $client->getClientId(),
            accountId: $stored->accountId,
            scopes: $stored->scopes,
            now: $now,
        );

        $refreshRecord = $this->refreshTokenIssuer->issue(
            accessTokenJti: $accessTokenPair->jti,
            clientId: $client->getClientId(),
            accountId: $stored->accountId,
            scopes: $stored->scopes,
            authTime: $now->getTimestamp(),
            now: $now,
        );

        return $this->success([
            'access_token' => $accessTokenPair->token,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_EXPIRY,
            'refresh_token' => $refreshRecord->token,
            'scope' => implode(' ', $stored->scopes),
            'id_token' => $idToken,
        ]);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function handleRefreshGrant(Request $request, array $form): Response
    {
        $clientId = is_string($form['client_id'] ?? null) ? $form['client_id'] : null;
        $clientSecret = is_string($form['client_secret'] ?? null) ? $form['client_secret'] : null;

        // Fall back to Basic auth for client credentials
        $authHeader = $request->headers->get('Authorization');
        if (is_string($authHeader) && stripos($authHeader, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);
                if ($id !== '') {
                    $clientId = $id;
                    $clientSecret = $secret !== '' ? $secret : null;
                }
            }
        }

        if ($clientId === null) {
            return $this->error(401, 'invalid_client', 'No client credentials provided.');
        }

        $client = $this->clientLookup->findByClientId($clientId);
        if ($client === null) {
            return $this->error(401, 'invalid_client', 'Unknown client_id.');
        }

        if ($client->isConfidential()) {
            if ($clientSecret === null) {
                return $this->error(401, 'invalid_client', 'Client authentication required.');
            }
            $hash = $client->getClientSecretHash();
            if ($hash === null || !password_verify($clientSecret, $hash)) {
                return $this->error(401, 'invalid_client', 'Client authentication failed.');
            }
        }

        $refreshTokenValue = is_string($form['refresh_token'] ?? null) ? $form['refresh_token'] : null;
        if ($refreshTokenValue === null || $refreshTokenValue === '') {
            return $this->error(400, 'invalid_request', 'Missing refresh_token.');
        }

        $now = ($this->clock)();

        $result = $this->refreshGrantHandler->handle(
            clientId: $clientId,
            refreshToken: $refreshTokenValue,
            issuer: $this->issuer,
            now: $now,
        );

        if (isset($result['error']) && is_string($result['error'])) {
            $status = isset($result['status']) && is_int($result['status']) ? $result['status'] : 400;

            return $this->error(
                $status,
                $result['error'],
                isset($result['error_description']) && is_string($result['error_description']) ? $result['error_description'] : '',
            );
        }

        return $this->success($result);
    }

    /**
     * @return array{0: ?string, 1: ?string} [client_id, client_secret]
     */
    private function extractClientCredentials(Request $request, TokenRequest $tokenRequest): array
    {
        $authHeader = $request->headers->get('Authorization');
        if (is_string($authHeader) && stripos($authHeader, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);
                if ($id !== '') {
                    return [$id, $secret];
                }
            }
        }

        $bodySecret = $request->request->get('client_secret');
        if ($tokenRequest->clientId !== null) {
            return [
                $tokenRequest->clientId,
                is_string($bodySecret) && $bodySecret !== '' ? $bodySecret : null,
            ];
        }

        return [null, null];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function success(array $payload): Response
    {
        $response = new JsonResponse($payload, 200);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function error(int $status, string $error, string $description): Response
    {
        $response = new JsonResponse([
            'error' => $error,
            'error_description' => $description,
        ], $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
