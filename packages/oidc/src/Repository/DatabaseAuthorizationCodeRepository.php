<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Repository;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\SchemaRequirement;

/**
 * DBAL-backed authorization code repository.
 *
 * Single-use is guaranteed by a single-statement atomic UPDATE with affected-rows
 * check: `UPDATE ... SET consumed_at = ? WHERE code = ? AND consumed_at IS NULL
 * AND expires_at > ?`. Concurrent consume() calls race on this one statement;
 * exactly one observes a non-zero affected-rows count.
 * @api
 */
final class DatabaseAuthorizationCodeRepository implements AuthorizationCodeRepositoryInterface
{
    private const TABLE = 'oidc_authorization_codes';

    public const TTL_SECONDS = 60;

    /** @var \Closure():int */
    private readonly \Closure $clock;

    private bool $schemaVerified = false;

    /**
     * @param (\Closure():int)|null $clock Clock returning Unix timestamp; defaults to time().
     */
    public function __construct(
        private readonly DBALDatabase $database,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function issue(
        string $clientId,
        AccountInterface $account,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        ?string $nonce = null,
    ): AuthorizationCode {
        $this->assertSchemaAvailable();

        $now = ($this->clock)();
        $expiresAt = $now + self::TTL_SECONDS;

        $code = bin2hex(random_bytes(32));
        $accountId = (string) $account->id();
        $scopesJson = json_encode($scopes, JSON_THROW_ON_ERROR);

        $this->database->insert(self::TABLE)
            ->values([
                'code' => $code,
                'client_id' => $clientId,
                'account_id' => $accountId,
                'redirect_uri' => $redirectUri,
                'scopes' => $scopesJson,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => $codeChallengeMethod,
                'issued_at' => $now,
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'nonce' => $nonce,
            ])
            ->execute();

        return new AuthorizationCode(
            code: $code,
            clientId: $clientId,
            accountId: $accountId,
            redirectUri: $redirectUri,
            scopes: $scopes,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
            issuedAt: $now,
            expiresAt: $expiresAt,
            consumedAt: null,
            nonce: $nonce,
        );
    }

    public function consume(string $code): ?AuthorizationCode
    {
        $this->assertSchemaAvailable();

        $now = ($this->clock)();

        $affected = $this->database->getConnection()->executeStatement(
            'UPDATE ' . self::TABLE . ' SET consumed_at = ? WHERE code = ? AND consumed_at IS NULL AND expires_at > ?',
            [$now, $code, $now],
        );

        if ($affected === 0) {
            return null;
        }

        $row = $this->fetchByCode($code);
        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function purgeExpired(): int
    {
        $this->assertSchemaAvailable();

        return $this->database->delete(self::TABLE)
            ->condition('expires_at', ($this->clock)(), '<=')
            ->execute();
    }

    private function assertSchemaAvailable(): void
    {
        if ($this->schemaVerified) {
            return;
        }

        SchemaRequirement::assertAvailable(
            $this->database,
            self::TABLE,
            [
                'code', 'client_id', 'account_id', 'redirect_uri', 'scopes',
                'code_challenge', 'code_challenge_method', 'issued_at',
                'expires_at', 'consumed_at', 'nonce',
            ],
            'waaseyaa/oidc:2026_08_12_000006_oidc_authorization_code_schema',
        );

        $this->schemaVerified = true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchByCode(string $code): ?array
    {
        foreach ($this->database->select(self::TABLE)->condition('code', $code)->execute() as $row) {
            return $row;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AuthorizationCode
    {
        $scopes = json_decode((string) $row['scopes'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($scopes)) {
            $scopes = [];
        }
        /** @var list<string> $scopes */
        $scopes = array_values(array_map('strval', $scopes));

        $nonce = $row['nonce'] ?? null;

        return new AuthorizationCode(
            code: (string) $row['code'],
            clientId: (string) $row['client_id'],
            accountId: (string) $row['account_id'],
            redirectUri: (string) $row['redirect_uri'],
            scopes: $scopes,
            codeChallenge: (string) $row['code_challenge'],
            codeChallengeMethod: (string) $row['code_challenge_method'],
            issuedAt: (int) $row['issued_at'],
            expiresAt: (int) $row['expires_at'],
            consumedAt: isset($row['consumed_at']) ? (int) $row['consumed_at'] : null,
            nonce: $nonce === null ? null : (string) $nonce,
        );
    }
}
