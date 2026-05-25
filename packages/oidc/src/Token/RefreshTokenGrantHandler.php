<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use DateTimeImmutable;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Handles grant_type=refresh_token with theft-detection chain cascade.
 *
 * Per RFC 6749 §6 + RFC 6819 §5.2.2.3: when a refresh token is re-used after
 * rotation, the entire chain is revoked and a security event is logged.
 *
 * @api
 */
final class RefreshTokenGrantHandler
{
    public function __construct(
        private readonly RefreshTokenIssuer $refreshTokenIssuer,
        private readonly AccessTokenIssuer $accessTokenIssuer,
        private readonly IdTokenMinter $idTokenMinter,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Handle a refresh_token grant.
     *
     * @return array<string, mixed> On success: OAuth token shape. On failure: array with 'error' key.
     */
    public function handle(
        string $clientId,
        string $refreshToken,
        string $issuer,
        DateTimeImmutable $now,
    ): array {
        $record = $this->refreshTokenIssuer->findByToken($refreshToken);

        if ($record === null || $record->isExpired($now->getTimestamp())) {
            return $this->error(400, 'invalid_grant', 'Refresh token is invalid or expired.');
        }

        if ($record->clientId !== $clientId) {
            return $this->error(400, 'invalid_grant', 'Refresh token was not issued to this client.');
        }

        // Theft detection: if already revoked, cascade-revoke the whole chain
        if ($record->isRevoked()) {
            $accessJtis = $this->refreshTokenIssuer->revokeChain($record->chainRootJti, $now);
            $this->accessTokenIssuer->revokeByJtis($accessJtis, $now);

            $this->logger->warning('refresh_token replay detected', [
                'chain_root_jti' => $record->chainRootJti,
                'client_id' => $clientId,
                'account_id' => $record->accountId,
            ]);

            return $this->error(400, 'invalid_grant', 'Refresh token has been revoked.');
        }

        // Rotate: revoke current pair, issue new pair
        $this->refreshTokenIssuer->revoke($record->jti, $now);
        $this->accessTokenIssuer->revoke($record->accessTokenJti, $now);

        $newAccessPair = $this->accessTokenIssuer->issue(
            clientId: $clientId,
            accountId: $record->accountId,
            scopes: $record->scopes,
            now: $now,
        );

        $newRefreshRecord = $this->refreshTokenIssuer->issue(
            accessTokenJti: $newAccessPair->jti,
            clientId: $clientId,
            accountId: $record->accountId,
            scopes: $record->scopes,
            authTime: $record->authTime,
            now: $now,
            chainRootJti: $record->chainRootJti,
        );

        // Re-issue ID token preserving original auth_time
        $idToken = $this->idTokenMinter->mint(
            issuer: $issuer,
            subject: $record->accountId,
            audience: $clientId,
            nonce: null,
            now: $now,
            authTime: $record->authTime,
        );

        return [
            'access_token' => $newAccessPair->token,
            'token_type' => 'Bearer',
            'expires_in' => $newAccessPair->expiresIn,
            'refresh_token' => $newRefreshRecord->token,
            'scope' => implode(' ', $record->scopes),
            'id_token' => $idToken,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(int $status, string $error, string $description): array
    {
        return [
            'status' => $status,
            'error' => $error,
            'error_description' => $description,
        ];
    }
}
