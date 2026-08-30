<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\User\Session\AuthenticatedSession;
use Waaseyaa\User\User;

#[CoversClass(AuthenticatedSession::class)]
final class AuthenticatedSessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function issueBindsTheUserAndGenerationToTheSession(): void
    {
        AuthenticatedSession::issue(new User(['uid' => 42]), 3);

        $this->assertSame(42, $_SESSION[AuthenticatedSession::USER_ID_KEY]);
        $this->assertSame(3, $_SESSION[AuthenticatedSession::GENERATION_KEY]);
    }

    #[Test]
    public function issueRejectsANegativeGenerationWithoutMutatingTheSession(): void
    {
        $_SESSION['unrelated'] = 'preserved';

        try {
            AuthenticatedSession::issue(new User(['uid' => 42]), -1);
            $this->fail('A negative session generation must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Session generation cannot be negative.', $exception->getMessage());
        }

        $this->assertSame(['unrelated' => 'preserved'], $_SESSION);
    }

    #[Test]
    public function clearIdentityRemovesOnlyAuthenticationState(): void
    {
        $_SESSION = [
            AuthenticatedSession::USER_ID_KEY => 42,
            AuthenticatedSession::GENERATION_KEY => 3,
            'unrelated' => 'preserved',
        ];

        AuthenticatedSession::clearIdentity();

        $this->assertSame(['unrelated' => 'preserved'], $_SESSION);
    }
}
