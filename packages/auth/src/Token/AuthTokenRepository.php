<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Token;

use Waaseyaa\Database\DatabaseInterface;

final class AuthTokenRepository implements AuthTokenRepositoryInterface
{
    private const TABLE = 'auth_tokens';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $secret,
    ) {}

    public function ensureSchema(): void
    {
        $schema = $this->db->schema();

        if ($schema->tableExists(self::TABLE)) {
            return;
        }

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
