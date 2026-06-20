<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Token;

use Waaseyaa\Database\DatabaseInterface;

/**
 * @api
 */
final class AuthTokenRepository implements AuthTokenRepositoryInterface
{
    private const TABLE = 'auth_tokens';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $secret,
    ) {}

    /**
     * Idempotent, race-safe schema bootstrap.
     *
     * This runs on the request hot path (AuthServiceProvider resolves the repo
     * during route registration). Under FrankenPHP's classic `php-server`, every
     * request boots the kernel afresh and the runtime serves requests across many
     * worker threads, so several cold boots can hit this concurrently. A plain
     * `tableExists()`-then-`createTable()` is TOCTOU: two threads both see the
     * table missing, both `CREATE TABLE`, and the loser gets the driver's
     * "table auth_tokens already exists" — a 500 on `/api/broadcast` that kills
     * the live Wayfinding beacon. So tolerate the concurrent create: if the table
     * exists afterwards, the race was benign; otherwise the failure is real and
     * rethrown.
     */
    public function ensureSchema(): void
    {
        $schema = $this->db->schema();

        if ($schema->tableExists(self::TABLE)) {
            return;
        }

        try {
            $schema->createTable(self::TABLE, [
                'fields' => [
                    'id' => ['type' => 'serial', 'not null' => true],
                    'user_id' => ['type' => 'text', 'not null' => false],
                    'token_hash' => ['type' => 'text', 'not null' => true],
                    'type' => ['type' => 'text', 'not null' => true],
                    'created_at' => ['type' => 'integer', 'not null' => true],
                    'expires_at' => ['type' => 'integer', 'not null' => true],
                    'consumed_at' => ['type' => 'integer', 'not null' => false],
                    'meta' => ['type' => 'text', 'not null' => false],
                    'created_by' => ['type' => 'text', 'not null' => false],
                ],
                'primary key' => ['id'],
            ]);
        } catch (\Throwable $e) {
            // A concurrent boot created the table between our check and create.
            // Re-fetch the schema for a fresh read (the world changed since the
            // guard above); only rethrow if the table genuinely isn't there.
            if (!$this->db->schema()->tableExists(self::TABLE)) {
                throw $e;
            }
        }
    }

    public function createToken(
        int|string|null $userId,
        string $type,
        int $ttlSeconds,
        ?array $meta = null,
        int|string|null $createdBy = null,
    ): string {
        // Revoke existing tokens of the same type for the same user.
        if ($userId !== null) {
            $this->revokeTokensForUser($userId, $type);
        }

        $plain = bin2hex(random_bytes(32));
        $hash = hash_hmac('sha256', $plain, $this->secret);
        $now = time();

        $this->db->insert(self::TABLE)
            ->values([
                'user_id' => $userId !== null ? (string) $userId : null,
                'token_hash' => $hash,
                'type' => $type,
                'created_at' => $now,
                'expires_at' => $now + $ttlSeconds,
                'consumed_at' => null,
                'meta' => $meta !== null ? json_encode($meta, JSON_THROW_ON_ERROR) : null,
                'created_by' => $createdBy !== null ? (string) $createdBy : null,
            ])
            ->execute();

        return $plain;
    }

    public function validateToken(string $plainToken, string $type): ?array
    {
        $hash = hash_hmac('sha256', $plainToken, $this->secret);
        $now = time();

        $rows = $this->db->select(self::TABLE)
            ->condition('token_hash', $hash)
            ->condition('type', $type)
            ->condition('expires_at', $now, '>')
            ->isNull('consumed_at')
            ->execute();

        foreach ($rows as $row) {
            $meta = null;
            if ($row['meta'] !== null) {
                $meta = json_decode($row['meta'], true, 512, JSON_THROW_ON_ERROR);
            }

            return [
                'id' => (int) $row['id'],
                'user_id' => $row['user_id'],
                'meta' => $meta,
            ];
        }

        return null;
    }

    public function consumeToken(int $tokenId): void
    {
        $this->db->update(self::TABLE)
            ->fields(['consumed_at' => time()])
            ->condition('id', $tokenId)
            ->execute();
    }

    public function revokeTokensForUser(int|string $userId, ?string $type = null): void
    {
        $delete = $this->db->delete(self::TABLE)
            ->condition('user_id', (string) $userId);

        if ($type !== null) {
            $delete->condition('type', $type);
        }

        $delete->execute();
    }

    public function pruneExpired(): int
    {
        $now = time();

        // Delete expired tokens.
        $expired = $this->db->delete(self::TABLE)
            ->condition('expires_at', $now, '<=')
            ->execute();

        // Delete consumed tokens.
        $consumed = $this->db->delete(self::TABLE)
            ->condition('consumed_at', null, 'IS NOT NULL')
            ->execute();

        return $expired + $consumed;
    }
}
