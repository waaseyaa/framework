<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Token\Bearer;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\DateTime\UtcEntityClock;

/**
 * Durable database-backed {@see BearerTokenStoreInterface} (#2177 F3).
 *
 * One `auth_bearer_token` row per credential. The plaintext secret is
 * generated from CSPRNG material, returned exactly once, and persisted only
 * as a SHA-256 verifier hash (the secret carries 256 bits of entropy, so a
 * fast hash is the correct verifier — there is no low-entropy password to
 * stretch) plus a separately derived non-secret display fingerprint.
 *
 * Wire format: `mbt_<16 hex id>.<64 hex secret>`. The id half is the public
 * identifier — it indexes the row lookup and appears in audit/admin surfaces;
 * the secret half never leaves the issuance response. Verification is
 * constant-time over the verifier hash (`hash_equals`), with a dummy
 * comparison on unknown ids so the id-existence timing surface stays flat.
 *
 * Runtime access verifies migration-owned schema and never installs it.
 */
final class DatabaseBearerTokenStore implements BearerTokenStoreInterface
{
    private const string TABLE = 'auth_bearer_token';

    private const string ID_PATTERN = '/^mbt_[0-9a-f]{16}$/';

    private const string TOKEN_PATTERN = '/^(mbt_[0-9a-f]{16})\.([0-9a-f]{64})$/';

    private const string AUDIENCE_PATTERN = '/^[a-z0-9][a-z0-9:._-]{0,63}$/';

    /** Printable ASCII, no leading/trailing blank, bounded length. */
    private const string SCOPE_PATTERN = '/^[\x21-\x7E](?:[\x20-\x7E]{0,126}[\x21-\x7E])?$/';

    private const int MAX_SCOPES = 32;

    private const int MAX_LABEL_LENGTH = 128;

    /** SHA-256 of an empty string — the dummy operand for unknown-id compares. */
    private const string DUMMY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private readonly EntityClockInterface $clock;

    private bool $schemaEnsured = false;

    public function __construct(
        private readonly DatabaseInterface $database,
        ?EntityClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new UtcEntityClock();
    }

    public function issue(
        int $accountUid,
        string $audience,
        array $scopes,
        ?int $ttlSeconds = null,
        string $label = '',
    ): IssuedBearerToken {
        self::assertAccountUid($accountUid);
        self::assertAudience($audience);
        $scopes = self::canonicalizeScopes($scopes);
        $ttlSeconds = self::assertTtl($ttlSeconds ?? self::DEFAULT_TTL_SECONDS);
        self::assertLabel($label);

        try {
            $this->ensureSchema();

            $issued = $this->newCredential($accountUid, $audience, $scopes, $ttlSeconds, $label, null);
            $this->insertRecord($issued);

            return $issued;
        } catch (\Throwable $e) {
            throw self::storeFailure('The bearer token could not be issued durably.', $e);
        }
    }

    public function verify(string $presentedToken, string $audience): ?BearerTokenRecord
    {
        // Shape refusals need no storage roundtrip and reveal nothing: the
        // format is public.
        if (\preg_match(self::TOKEN_PATTERN, $presentedToken, $parts) !== 1) {
            return null;
        }
        if (\preg_match(self::AUDIENCE_PATTERN, $audience) !== 1) {
            return null;
        }

        try {
            $this->ensureSchema();

            $row = $this->fetchRow($parts[1]);
            $presentedHash = \hash('sha256', $presentedToken);

            // Level the unknown-id timing surface against the known-id path:
            // an absent row compares against the dummy digest (SHA-256 of the
            // empty string, which no well-formed token hashes to), so both
            // outcomes spend the same comparison work.
            $storedHash = $row === null ? self::DUMMY_HASH : (string) $row['secret_hash'];
            if (!\hash_equals($storedHash, $presentedHash) || $row === null) {
                return null;
            }

            $record = $this->hydrate($row);

            if ($record->isRevoked()
                || $record->isExpiredAt($this->utc($this->clock->now()))
                || !\hash_equals($record->audience, $audience)
            ) {
                return null;
            }

            return $record;
        } catch (\Throwable) {
            // Storage outage, malformed stored record, clock trouble: a
            // credential check that cannot give a positive durable answer
            // gives a negative one.
            return null;
        }
    }

    public function rotate(string $tokenId, ?int $ttlSeconds = null): IssuedBearerToken
    {
        if ($ttlSeconds !== null) {
            $ttlSeconds = self::assertTtl($ttlSeconds);
        }
        if (\preg_match(self::ID_PATTERN, $tokenId) !== 1) {
            throw new BearerTokenStoreException('The bearer token id is not recognised.');
        }

        try {
            $this->ensureSchema();
        } catch (\Throwable $e) {
            throw self::storeFailure('The bearer token store is unavailable.', $e);
        }

        $transaction = $this->database->transaction('bearer-token-rotate');

        try {
            $row = $this->fetchRow($tokenId);
            if ($row === null) {
                throw new BearerTokenStoreException('The bearer token id is not recognised.');
            }

            $old = $this->hydrate($row);
            $now = $this->utc($this->clock->now());
            if ($old->isRevoked()) {
                throw new BearerTokenStoreException('A revoked bearer token cannot be rotated; issue a new one.');
            }
            if ($old->isExpiredAt($now)) {
                throw new BearerTokenStoreException('An expired bearer token cannot be rotated; issue a new one.');
            }

            $successorTtl = $ttlSeconds
                ?? \max(self::MIN_TTL_SECONDS, $old->expiresAt->getTimestamp() - $old->issuedAt->getTimestamp());

            $issued = $this->newCredential(
                $old->accountUid,
                $old->audience,
                $old->scopes,
                $successorTtl,
                $old->label,
                $old->id,
            );
            $this->insertRecord($issued);

            // The predecessor dies in the same transaction the successor is
            // born in. The `revoked_at IS NULL` guard makes a concurrent
            // revoke/rotate lose exactly one of the two races — an affected
            // count other than 1 aborts everything, so a partial failure can
            // never leave two usable credentials (or zero).
            $revoked = $this->database->update(self::TABLE)
                ->fields(['revoked_at' => $now->format('Y-m-d H:i:s.u')])
                ->condition('id', $old->id)
                ->condition('revoked_at', null, 'IS NULL')
                ->execute();
            if ($revoked !== 1) {
                throw new BearerTokenStoreException('The bearer token was revoked or rotated concurrently.');
            }

            $transaction->commit();

            return $issued;
        } catch (\Throwable $e) {
            $this->rollBackQuietly($transaction);

            if ($e instanceof BearerTokenStoreException) {
                throw $e;
            }

            throw self::storeFailure('The bearer token rotation could not be made durable.', $e);
        }
    }

    public function revoke(string $tokenId): void
    {
        if (\preg_match(self::ID_PATTERN, $tokenId) !== 1) {
            throw new BearerTokenStoreException('The bearer token id is not recognised.');
        }

        try {
            $this->ensureSchema();

            $revoked = $this->database->update(self::TABLE)
                ->fields(['revoked_at' => $this->utc($this->clock->now())->format('Y-m-d H:i:s.u')])
                ->condition('id', $tokenId)
                ->condition('revoked_at', null, 'IS NULL')
                ->execute();

            if ($revoked === 1 || $this->fetchRow($tokenId) !== null) {
                // Freshly revoked, or already durably revoked: idempotent.
                return;
            }
        } catch (\Throwable $e) {
            throw self::storeFailure('The bearer token revocation could not be made durable.', $e);
        }

        throw new BearerTokenStoreException('The bearer token id is not recognised.');
    }

    public function find(string $tokenId): ?BearerTokenRecord
    {
        if (\preg_match(self::ID_PATTERN, $tokenId) !== 1) {
            return null;
        }

        try {
            $this->ensureSchema();
            $row = $this->fetchRow($tokenId);

            return $row === null ? null : $this->hydrate($row);
        } catch (\Throwable $e) {
            throw self::storeFailure('The bearer token store could not be read.', $e);
        }
    }

    public function all(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('The bearer token listing limit must be between 1 and 1000.');
        }

        try {
            $this->ensureSchema();

            $rows = \iterator_to_array($this->database->query(\sprintf(
                'SELECT * FROM %s ORDER BY issued_at DESC, id LIMIT %d',
                self::TABLE,
                $limit,
            )));

            return \array_map($this->hydrate(...), \array_values($rows));
        } catch (\Throwable $e) {
            throw self::storeFailure('The bearer token store could not be read.', $e);
        }
    }

    /** Read-only compatibility alias; schema installation belongs to migrations. */
    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        SchemaRequirement::assertAvailable(
            $this->database,
            self::TABLE,
            [
                'id', 'account_uid', 'audience', 'scopes', 'label',
                'secret_hash', 'fingerprint', 'issued_at', 'expires_at',
                'revoked_at', 'rotated_from',
            ],
            'waaseyaa/auth:2026_08_12_000001_auth_runtime_schema',
        );

        $this->schemaEnsured = true;
    }

    /** @param list<string> $scopes */
    private function newCredential(
        int $accountUid,
        string $audience,
        array $scopes,
        int $ttlSeconds,
        string $label,
        ?string $rotatedFrom,
    ): IssuedBearerToken {
        $id = 'mbt_' . \bin2hex(\random_bytes(8));
        $wire = $id . '.' . \bin2hex(\random_bytes(32));
        $now = $this->utc($this->clock->now());

        $record = new BearerTokenRecord(
            id: $id,
            accountUid: $accountUid,
            audience: $audience,
            scopes: $scopes,
            label: $label,
            fingerprint: \substr(\hash('sha256', 'fp:' . $wire), 0, 16),
            issuedAt: $now,
            expiresAt: $now->modify(\sprintf('+%d seconds', $ttlSeconds)),
            revokedAt: null,
            rotatedFrom: $rotatedFrom,
        );

        return new IssuedBearerToken($record, $wire);
    }

    private function insertRecord(IssuedBearerToken $issued): void
    {
        $record = $issued->record;

        $this->database->insert(self::TABLE)->values([
            'id' => $record->id,
            'account_uid' => $record->accountUid,
            'audience' => $record->audience,
            'scopes' => \json_encode($record->scopes, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'label' => $record->label,
            'secret_hash' => \hash('sha256', $issued->secret),
            'fingerprint' => $record->fingerprint,
            'issued_at' => $record->issuedAt->format('Y-m-d H:i:s.u'),
            'expires_at' => $record->expiresAt->format('Y-m-d H:i:s.u'),
            'revoked_at' => null,
            'rotated_from' => $record->rotatedFrom,
        ])->execute();
    }

    /** @return array<string, mixed>|null */
    private function fetchRow(string $tokenId): ?array
    {
        $rows = \iterator_to_array($this->database->query(
            \sprintf('SELECT * FROM %s WHERE id = :id', self::TABLE),
            ['id' => $tokenId],
        ));

        return $rows === [] ? null : $rows[\array_key_first($rows)];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): BearerTokenRecord
    {
        $scopes = \json_decode((string) $row['scopes'], true, 8, \JSON_THROW_ON_ERROR);
        if (!\is_array($scopes) || $scopes !== \array_values(\array_filter($scopes, \is_string(...)))) {
            throw new BearerTokenStoreException('A stored bearer token record is malformed.');
        }
        if (\preg_match('/^[0-9a-f]{64}$/', (string) $row['secret_hash']) !== 1) {
            throw new BearerTokenStoreException('A stored bearer token record is malformed.');
        }

        /** @var list<string> $scopes */
        return new BearerTokenRecord(
            id: (string) $row['id'],
            accountUid: (int) $row['account_uid'],
            audience: (string) $row['audience'],
            scopes: $scopes,
            label: (string) $row['label'],
            fingerprint: (string) $row['fingerprint'],
            issuedAt: $this->parseStoredTime((string) $row['issued_at']),
            expiresAt: $this->parseStoredTime((string) $row['expires_at']),
            revokedAt: isset($row['revoked_at'])
                ? $this->parseStoredTime((string) $row['revoked_at'])
                : null,
            rotatedFrom: isset($row['rotated_from'])
                ? (string) $row['rotated_from']
                : null,
        );
    }

    private static function assertAccountUid(int $accountUid): void
    {
        // Sentinels (AnonymousUser 0, DevAdminAccount PHP_INT_MAX) are not
        // token owners: the owner must be a real, loadable account so the
        // authenticated principal keeps its separation-of-duties meaning.
        if ($accountUid <= 0 || $accountUid === \PHP_INT_MAX) {
            throw new \InvalidArgumentException('A bearer token owner must be a real account uid.');
        }
    }

    private static function assertAudience(string $audience): void
    {
        if (\preg_match(self::AUDIENCE_PATTERN, $audience) !== 1) {
            throw new \InvalidArgumentException(
                'A bearer token audience must be 1-64 chars of [a-z0-9:._-] starting alphanumeric.',
            );
        }
    }

    private static function assertTtl(int $ttlSeconds): int
    {
        if ($ttlSeconds < self::MIN_TTL_SECONDS || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException(\sprintf(
                'A bearer token lifetime must be between %d and %d seconds.',
                self::MIN_TTL_SECONDS,
                self::MAX_TTL_SECONDS,
            ));
        }

        return $ttlSeconds;
    }

    private static function assertLabel(string $label): void
    {
        if (\strlen($label) > self::MAX_LABEL_LENGTH
            || ($label !== '' && \preg_match('/^[\x20-\x7E]+$/', $label) !== 1)
        ) {
            throw new \InvalidArgumentException(
                'A bearer token label must be at most 128 printable ASCII characters.',
            );
        }
    }

    /**
     * @param list<mixed> $scopes
     * @return non-empty-list<string>
     */
    private static function canonicalizeScopes(array $scopes): array
    {
        $canonical = [];
        foreach ($scopes as $scope) {
            if (!\is_string($scope)) {
                throw new \InvalidArgumentException('Bearer token scopes must be strings.');
            }
            $scope = \trim($scope);
            if (\preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
                throw new \InvalidArgumentException(
                    'A bearer token scope must be 1-128 printable ASCII characters with no control characters.',
                );
            }
            $canonical[$scope] = true;
        }

        if ($canonical === []) {
            throw new \InvalidArgumentException(
                'A bearer token requires at least one explicit scope — least privilege is not optional.',
            );
        }
        if (\count($canonical) > self::MAX_SCOPES) {
            throw new \InvalidArgumentException(\sprintf('A bearer token carries at most %d scopes.', self::MAX_SCOPES));
        }

        $canonical = \array_keys($canonical);
        \sort($canonical, \SORT_STRING);

        return $canonical;
    }

    private static function storeFailure(string $message, \Throwable $cause): \Throwable
    {
        if ($cause instanceof BearerTokenStoreException || $cause instanceof \InvalidArgumentException) {
            return $cause;
        }

        return new BearerTokenStoreException($message, 0, $cause);
    }

    private function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone('UTC'));
    }

    private function parseStoredTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function rollBackQuietly(\Waaseyaa\Database\TransactionInterface $transaction): void
    {
        try {
            $transaction->rollBack();
        } catch (\Throwable) {
            // The caller's outcome is what matters.
        }
    }
}
