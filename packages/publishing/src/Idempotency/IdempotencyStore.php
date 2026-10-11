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
 * DIFFERENT request raises IDEMPOTENCY_CONFLICT; an unknown key executes the
 * operation and stores its response. Response entries expire after the TTL;
 * erased keys remain permanent content-free refusal markers.
 *
 * Schema is migration-owned. Responses are stored as JSON. Publishing snapshots
 * require explicit erasure alongside entity deletion; never store credentials.
 *
 * @api
 */
final class IdempotencyStore
{
    private const string TABLE = 'publishing_idempotency';
    private const int DEFAULT_TTL_SECONDS = 172800; // 48 h

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
        $this->sweep();

        $storageKey = $namespace === ''
            ? $idempotencyKey
            : hash('sha256', $namespace . "\0" . $idempotencyKey);
        $requestHash = $this->hashRequest($operationName, $request);
        $transaction = $this->database->transaction('publishing_idempotency');

        try {
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
     * Returns the number of newly erased rows; repeating erasure returns zero.
     * Keys already swept before this operation cannot be recovered.
     */
    public function eraseContentResponses(string $uuid): int
    {
        if (trim($uuid) === '') {
            throw new \InvalidArgumentException('Entity UUID must not be empty.');
        }
        $this->ensureTable();
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
        $this->database->delete(self::TABLE)
            ->condition('created_at', ($this->clock)() - $this->ttlSeconds, '<')
            ->condition('response_json', 'null', '<>')
            ->execute();
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
