<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\User\PasswordResetTokenRepository;

#[CoversClass(PasswordResetTokenRepository::class)]
final class PasswordResetTokenRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PasswordResetTokenRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->repo = new PasswordResetTokenRepository($this->pdo);
    }

    #[Test]
    public function creates_token_and_validates_it(): void
    {
        $token = $this->repo->createToken(42);
        $this->assertSame(64, strlen($token));

        $userId = $this->repo->validateToken($token);
        $this->assertSame('42', $userId);
    }

    #[Test]
    public function invalidates_previous_tokens_for_same_user(): void
    {
        $first = $this->repo->createToken(42);
        $second = $this->repo->createToken(42);

        $this->assertNull($this->repo->validateToken($first));
        $this->assertSame('42', $this->repo->validateToken($second));
    }

    #[Test]
    public function consume_marks_token_as_used(): void
    {
        $token = $this->repo->createToken(42);
        $this->repo->consumeToken($token);

        $this->assertNull($this->repo->validateToken($token));
    }

    #[Test]
    public function expired_token_returns_null(): void
    {
        // Insert a token that expired 1 second ago
        $this->repo->createToken(42);
        $this->pdo->exec("UPDATE password_reset_tokens SET expires_at = " . (time() - 1));

        $token = $this->pdo->query("SELECT token FROM password_reset_tokens")->fetchColumn();
        $this->assertNull($this->repo->validateToken($token));
    }

    #[Test]
    public function unknown_token_returns_null(): void
    {
        $this->assertNull($this->repo->validateToken('nonexistent'));
    }
}
