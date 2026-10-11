<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Idempotency;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Exception\TransactionCompletionException;
use Waaseyaa\Database\Schema\SchemaRequirement;
use Waaseyaa\Publishing\Exception\ContentNotFoundException;
use Waaseyaa\Publishing\Exception\IdempotencyConflictException;

/**
 * Durable idempotency-key store for content mutations.
 *
 * Contract (Stripe-shaped): the same key with the SAME canonicalized request
 * replays the stored response without re-executing; the same key with a
 * DIFFERENT request raises IDEMPOTENCY_CONFLICT; an admitted unknown key
 * executes and stores its response. Expired responses and erased responses
 * become permanent content-free refusal markers.
 *
 * Schema is migration-owned. Responses are stored as JSON. Publishing snapshots
 * require explicit erasure alongside entity deletion. The reserved admission
 * row holds a random HMAC secret, never user credentials or response content.
 *
 * @api
 */
final class IdempotencyStore
{
    private const string TABLE = 'publishing_idempotency';
    private const int DEFAULT_TTL_SECONDS = 172800; // 48 h
    private const string ADMISSION_KEY = '__publishing_issued_key_admission_v1__';

    private bool $tableEnsured = false;

    /** @var \Closure(): int Injectable clock for tests; defaults to time(). */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Execute `$operation` exactly once for this (key, request) pair.
     *
     * @template T of array
     * @param array<string, mixed> $request The canonical request payload (hashed for comparison).
     * @param \Closure(): T $operation
     * @param (\Closure(T): void)|null $authorizeReplay Current authority for a matching stored response; never repeats the operation.
     * @return T
     *
     * @throws IdempotencyConflictException Same key, different request payload.
     * @throws TransactionCompletionException The transaction committed but completion work failed.
     */
    public function execute(string $idempotencyKey, string $operationName, array $request, \Closure $operation, string $namespace = '', ?\Closure $authorizeReplay = null): array
    {
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('Idempotency key must not be empty.');
        }
        $this->ensureTable();

        $storageKey = $namespace === ''
            ? $idempotencyKey
            : hash('sha256', $namespace . "\0" . $idempotencyKey);
        $requestHash = $this->hashRequest($operationName, $request);
        $transaction = $this->database->transaction('publishing_idempotency');

        try {
            $existing = $this->fetch($storageKey);
            if ($existing !== null && $existing['response_json'] === 'null') {
                // An erased retry must not even sweep unrelated expired rows.
                throw new ContentNotFoundException('Erased publication replay.');
            }
            $this->sweep();
            $existing = $this->fetch($storageKey);
            if ($existing !== null) {
                if ($existing['response_json'] === 'null') {
                    throw new ContentNotFoundException('Erased publication replay.');
                }
                if (!hash_equals($existing['request_hash'], $requestHash)) {
                    throw new IdempotencyConflictException($idempotencyKey);
                }

                /** @var array<string, mixed> $response */
                $response = json_decode($existing['response_json'], true, 512, JSON_THROW_ON_ERROR);
                $authorizeReplay?->__invoke($response);
            } else {
                if ($this->issuedKeyAdmissionEnabled() && !$this->isCurrentIssuedKey($idempotencyKey)) {
                    throw new ContentNotFoundException('Publication replay key is no longer admitted.');
                }
                $response = $operation();

                // The mutation and its replay record share one database
                // transaction. A projection or serialization failure after an entity
                // save cannot strand persisted content outside the idempotency
                // contract, and a racing duplicate-key insert rolls its mutation
                // back with it.
                $this->database->query(
                    'INSERT INTO ' . self::TABLE . ' (idem_key, operation, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?)',
                    [
                        $storageKey,
                        $operationName,
                        $requestHash,
                        json_encode($response, JSON_THROW_ON_ERROR),
                        ($this->clock)(),
                    ],
                );
            }
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }

        try {
            $transaction->commit();
        } catch (TransactionCompletionException $failure) {
            // The database is already committed; never report or attempt a
            // fictional rollback for completion work that ran afterward.
            throw $failure;
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }

        return $response;
    }

    /**
     * Erase every retained publication snapshot for one canonical entity UUID.
     *
     * This is a trusted lifecycle operation, not an authorization endpoint.
     * Capture UUID before deletion and compose both operations in one outer
     * transaction on this database. UUID must be unique across the store.
     * ContentPublisher records have opaque hashed keys. Direct store callers
     * must ensure their keys contain no content; this operation retains keys.
     * Returns the number of newly erased rows; repeating erasure returns zero.
     * Keys swept by older versions cannot be recovered. Enable issued-key
     * admission before erasure to reject their unknown legacy retries.
     */
    public function eraseContentResponses(string $uuid): int
    {
        if (trim($uuid) === '') {
            throw new \InvalidArgumentException('Entity UUID must not be empty.');
        }
        $this->ensureTable();
        if (!$this->issuedKeyAdmissionEnabled()) {
            throw new \LogicException('Enable issued-key admission before publication replay erasure.');
        }
        $transaction = $this->database->transaction('publishing_replay_erasure');
        $erased = 0;
        try {
            $rows = $this->database->select(self::TABLE)
                ->fields(self::TABLE, ['idem_key', 'response_json'])
                ->execute();
            foreach ($rows as $row) {
                $response = json_decode((string) $row['response_json'], true, 512, JSON_THROW_ON_ERROR);
                if (!\is_array($response) || ($response['uuid'] ?? null) !== $uuid) {
                    continue;
                }
                // Retain only the opaque key. Even the content-derived request
                // fingerprint is removed; every subsequent use must refuse.
                $erased += $this->database->update(self::TABLE)
                    ->fields(['response_json' => 'null', 'operation' => '', 'request_hash' => '', 'created_at' => 0])
                    ->condition('idem_key', (string) $row['idem_key'])
                    ->execute();
            }
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }
        try {
            $transaction->commit();
        } catch (TransactionCompletionException $failure) {
            throw $failure;
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }

        return $erased;
    }

    /** Issue an opaque client key whose admission ends after this store's TTL. */
    public function issueKey(): string
    {
        $this->ensureTable();
        $secret = $this->admissionSecret();
        if ($secret === null) {
            throw new \LogicException('Enable issued-key admission before issuing publication keys.');
        }
        $payload = ($this->clock)() . '.' . bin2hex(random_bytes(16));

        return 'pub1.' . $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Irreversibly reject unknown legacy keys, including keys swept before this
     * version. Existing retained responses can still replay until expiry.
     * Coordinate this cutover with ingress so no old request is in flight.
     */
    public function enableIssuedKeyAdmission(): void
    {
        $this->ensureTable();
        $transaction = $this->database->transaction('publishing_key_admission');
        try {
            $existing = $this->fetch(self::ADMISSION_KEY);
            if ($existing === null) {
                $this->database->query(
                    'INSERT INTO ' . self::TABLE . ' (idem_key, operation, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?)',
                    [self::ADMISSION_KEY, '', bin2hex(random_bytes(32)), 'null', 0],
                );
            } elseif ($this->admissionSecret() === null) {
                throw new \LogicException('Reserved publication admission row is invalid.');
            }
            $transaction->commit();
        } catch (TransactionCompletionException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            $transaction->rollBack();
            throw $failure;
        }
    }

    /** @param array<string, mixed> $request */
    private function hashRequest(string $operationName, array $request): string
    {
        return hash('sha256', $operationName . "\0" . json_encode(
            self::ksortRecursive($request),
            JSON_THROW_ON_ERROR,
        ));
    }

    /** @return ?array{request_hash: string, response_json: string} */
    private function fetch(string $idempotencyKey): ?array
    {
        $result = $this->database->select(self::TABLE)
            ->fields(self::TABLE, ['request_hash', 'response_json'])
            ->condition('idem_key', $idempotencyKey)
            ->execute();
        foreach ($result as $row) {
            return ['request_hash' => (string) $row['request_hash'], 'response_json' => (string) $row['response_json']];
        }

        return null;
    }

    private function sweep(): void
    {
        $this->database->update(self::TABLE)
            ->fields(['response_json' => 'null', 'operation' => '', 'request_hash' => '', 'created_at' => 0])
            ->condition('created_at', ($this->clock)() - $this->ttlSeconds, '<')
            ->condition('response_json', 'null', '<>')
            ->execute();
    }

    private function issuedKeyAdmissionEnabled(): bool
    {
        return $this->admissionSecret() !== null;
    }

    private function admissionSecret(): ?string
    {
        $row = $this->fetch(self::ADMISSION_KEY);
        if ($row === null || $row['response_json'] !== 'null' || preg_match('/^[a-f0-9]{64}$/D', $row['request_hash']) !== 1) {
            return null;
        }

        return hex2bin($row['request_hash']);
    }

    private function isCurrentIssuedKey(string $key): bool
    {
        if (preg_match('/^pub1\.((0|[1-9][0-9]*)\.[a-f0-9]{32})\.([a-f0-9]{64})$/D', $key, $matches) !== 1) {
            return false;
        }
        $secret = $this->admissionSecret();
        if ($secret === null || !hash_equals(hash_hmac('sha256', $matches[1], $secret), $matches[3])) {
            return false;
        }
        $issuedAt = filter_var($matches[2], FILTER_VALIDATE_INT);
        $now = ($this->clock)();

        return $issuedAt !== false && $issuedAt <= $now && $issuedAt >= $now - $this->ttlSeconds;
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }
        SchemaRequirement::assertAvailable(
            $this->database,
            self::TABLE,
            ['idem_key', 'operation', 'request_hash', 'response_json', 'created_at'],
            'waaseyaa/publishing:2026_08_12_000001_idempotency_schema',
        );
        $this->tableEnsured = true;
    }

    /** @param array<string, mixed> $value */
    private static function ksortRecursive(array $value): array
    {
        ksort($value);
        foreach ($value as $k => $v) {
            if (\is_array($v) && $v !== [] && !array_is_list($v)) {
                $value[$k] = self::ksortRecursive($v);
            }
        }

        return $value;
    }
}
