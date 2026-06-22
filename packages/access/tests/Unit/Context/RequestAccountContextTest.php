<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\RequestAccountContext;

#[CoversClass(RequestAccountContext::class)]
final class RequestAccountContextTest extends TestCase
{
    #[Test]
    public function fresh_instance_has_no_current_account(): void
    {
        $context = new RequestAccountContext();

        $this->assertNull($context->current());
    }

    #[Test]
    public function set_account_is_returned_by_identity(): void
    {
        $context = new RequestAccountContext();
        $account = $this->account(42);

        $context->set($account);

        $this->assertSame($account, $context->current());
    }

    #[Test]
    public function set_null_clears_the_context(): void
    {
        $context = new RequestAccountContext();
        $context->set($this->account(42));

        $context->set(null);

        $this->assertNull($context->current());
    }

    #[Test]
    public function set_overwrites_the_previous_account(): void
    {
        $context = new RequestAccountContext();
        $first = $this->account(1);
        $second = $this->account(2);

        $context->set($first);
        $context->set($second);

        $this->assertSame($second, $context->current());
    }

    #[Test]
    public function anonymous_account_is_stored_like_any_other(): void
    {
        $context = new RequestAccountContext();
        $anonymous = $this->account(0, authenticated: false);

        $context->set($anonymous);

        $this->assertSame($anonymous, $context->current());
        $this->assertSame(0, $context->current()->id());
    }

    private function account(int $id, bool $authenticated = true): AccountInterface
    {
        return new class($id, $authenticated) implements AccountInterface {
            public function __construct(
                private readonly int $id,
                private readonly bool $authenticated,
            ) {}

            public function id(): int|string
            {
                return $this->id;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }
        };
    }
}
