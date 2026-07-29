<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Idempotency;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Publishing\Exception\IdempotencyConflictException;

/**
 * Durable idempotency-key store for content mutations.
 *
 * Contract (Stripe-shaped): the same key with the SAME canonicalized request
 * replays the stored response without re-executing; the same key with a
 * DIFFERENT request raises IDEMPOTENCY_CONFLICT; an unknown key executes the
 * operation and stores its response. Entries expire after the TTL and are
 * swept opportunistically.
 *
 * Table is self-creating with portable, non-reserved column names (the
 * `rate_limits` pattern). Responses are stored as JSON and must never contain
 * credentials or personal data — callers store the same payload they return
 * to the agent.
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
     * @return T
     *
     * @throws IdempotencyConflictException Same key, different request payload.
     */
    public function execute(string $idempotencyKey, string $operationName, array $request, \Closure $operation): array
    {
        if (trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('Idempotency key must not be empty.');
        }
        $this->ensureTable();
        $this->sweep();

        $requestHash = $this->hashRequest($operationName, $request);

        $existing = $this->fetch($idempotencyKey);
        if ($existing !== null) {
            if (!hash_equals($existing['request_hash'], $requestHash)) {
                throw new IdempotencyConflictException($idempotencyKey);
            }

            /** @var array<string, mixed> $replay */
            $replay = json_decode($existing['response_json'], true, 512, JSON_THROW_ON_ERROR);

            return $replay;
        }

        $response = $operation();

        // Parameterized statement instead of the insert builder: the builder's
        // execute() reads lastInsertId(), which throws on string-primary-key
        // tables (no identity column here).
        $this->database->query(
            'INSERT INTO ' . self::TABLE . ' (idem_key, operation, request_hash, response_json, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                $idempotencyKey,
                $operationName,
                $requestHash,
                json_encode($response, JSON_THROW_ON_ERROR),
                ($this->clock)(),
            ],
        );

        return $response;
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
            ->execute();
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }
        $schema = $this->database->schema();
        if (!$schema->tableExists(self::TABLE)) {
            $schema->createTable(self::TABLE, [
                'fields' => [
                    'idem_key' => ['type' => 'varchar', 'length' => 191, 'not null' => true],
                    'operation' => ['type' => 'varchar', 'length' => 128, 'not null' => true],
                    'request_hash' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
                    'response_json' => ['type' => 'text', 'not null' => true],
                    'created_at' => ['type' => 'int', 'not null' => true],
                ],
                'primary key' => ['idem_key'],
            ]);
        }
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
