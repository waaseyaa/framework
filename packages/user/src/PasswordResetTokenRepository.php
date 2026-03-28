<?php

declare(strict_types=1);

namespace Waaseyaa\User;

final class PasswordResetTokenRepository
{
    private bool $tableEnsured = false;

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Create a reset token for a user. Invalidates any existing tokens.
     * Returns the 64-char hex token string.
     */
    public function createToken(int|string $userId): string
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);

        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (token, user_id, expires_at, used_at) VALUES (:token, :uid, :expires, NULL)',
        );
        $stmt->execute([
            'token' => $token,
            'uid' => $userId,
            'expires' => time() + 3600,
        ]);

        return $token;
    }

    /**
     * Validate a token. Returns user_id if valid, null otherwise.
     */
    public function validateToken(string $token): int|string|null
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM password_reset_tokens WHERE token = :token AND expires_at > :now AND used_at IS NULL',
        );
        $stmt->execute(['token' => $token, 'now' => time()]);
        $result = $stmt->fetchColumn();

        return $result !== false ? $result : null;
    }

    /**
     * Mark a token as used (consumed).
     */
    public function consumeToken(string $token): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = :now WHERE token = :token');
        $stmt->execute(['token' => $token, 'now' => time()]);
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS password_reset_tokens ('
            . 'token TEXT PRIMARY KEY, '
            . 'user_id TEXT NOT NULL, '
            . 'expires_at INTEGER NOT NULL, '
            . 'used_at INTEGER'
            . ')',
        );
        $this->tableEnsured = true;
    }
}
