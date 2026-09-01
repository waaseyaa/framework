<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Token;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\DateTime\UtcEntityClock;

/**
 * @api
 */
final class AuthTokenRepository implements AuthTokenRepositoryInterface
{
    private const TABLE = 'auth_tokens';

    /** Single time authority for issuance, validation, consumption, and pruning. */
    private readonly EntityClockInterface $clock;

    private bool $schemaVerified = false;

    public function __construct(
        private readonly DatabaseInterface $db,
        #[\SensitiveParameter]
        private readonly string $secret,
        ?EntityClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new UtcEntityClock();
    }

    /** Read-only compatibility alias; schema installation belongs to migrations. */
    public function ensureSchema(): void
    {
        if ($this->schemaVerified) {
            return;
        }

        SchemaRequirement::assertAvailable(
            $this->db,
            self::TABLE,
            ['id', 'user_id', 'token_hash', 'type', 'created_at', 'expires_at', 'consumed_at', 'meta', 'created_by'],
            'waaseyaa/auth:2026_08_12_000001_auth_runtime_schema',
        );

        $this->schemaVerified = true;
    }

    public function createToken(
        int|string|null $userId,
        string $type,
        int $ttlSeconds,
        ?array $meta = null,
        int|string|null $createdBy = null,
    ): string {
        $this->ensureSchema();

        // Revoke existing tokens of the same type for the same user.
        if ($userId !== null) {
            $this->revokeTokensForUser($userId, $type);
        }

        $plain = bin2hex(random_bytes(32));
        $hash = hash_hmac('sha256', $plain, $this->secret);
        $now = $this->nowTimestamp();

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
        $this->ensureSchema();

        $hash = hash_hmac('sha256', $plainToken, $this->secret);
        $now = $this->nowTimestamp();

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
        $this->ensureSchema();

        $this->db->update(self::TABLE)
            ->fields(['consumed_at' => $this->nowTimestamp()])
            ->condition('id', $tokenId)
            ->execute();
    }

    public function consumeTokenIfAvailable(int $tokenId, string $type, int|string|null $userId): bool
    {
        $this->ensureSchema();

        // One clock read serves the whole operation: the same instant both
        // proves the row is unexpired and stamps `consumed_at`. Re-reading the
        // clock here, or accepting an instant the caller read during an earlier
        // validateToken(), would only move the race rather than close it.
        $now = $this->nowTimestamp();

        $update = $this->db->update(self::TABLE)
            ->fields(['consumed_at' => $now])
            ->condition('id', $tokenId)
            ->condition('type', $type)
            // Strictly the same boundary validateToken() applies, so a token
            // that validates at an instant is still consumable at that instant.
            ->condition('expires_at', $now, '>')
            ->condition('consumed_at', null, 'IS NULL');

        // `user_id` is stored as the string form of the owner id (NULL for an
        // unowned invite), so the predicate matches how createToken() wrote it.
        $update = $userId === null
            ? $update->condition('user_id', null, 'IS NULL')
            : $update->condition('user_id', (string) $userId);

        return $update->execute() === 1;
    }

    public function revokeTokensForUser(int|string $userId, ?string $type = null): void
    {
        $this->ensureSchema();

        $delete = $this->db->delete(self::TABLE)
            ->condition('user_id', (string) $userId);

        if ($type !== null) {
            $delete->condition('type', $type);
        }

        $delete->execute();
    }

    public function pruneExpired(): int
    {
        $this->ensureSchema();

        $now = $this->nowTimestamp();

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

    private function nowTimestamp(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** @return array{secret: string} */
    public function __debugInfo(): array
    {
        return ['secret' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Auth token HMAC keys cannot be serialized.');
    }
}
