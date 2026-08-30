<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\LocalOperator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorAccountContextGuard;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorPrincipal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorRefusal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorTransportAttestation;

/**
 * ADR-022 D-6 R-4 — entity ownership and attribution.
 *
 * Without this guard R-4 would be a docblock warning: an
 * `AccountContextInterface` is a deliberately dumb holder,
 * `EntityRepository::resolveActor()` casts the ambient account's `id()` to
 * `int`, and `(int) 'local-operator:stdio'` is `0` — the `AnonymousUser` id.
 * D-6 forbids exactly that silent downgrade.
 */
#[CoversClass(LocalOperatorAccountContextGuard::class)]
final class LocalOperatorAccountContextGuardTest extends TestCase
{
    private function inner(): AccountContextInterface
    {
        return new class implements AccountContextInterface {
            private ?AccountInterface $account = null;

            public function current(): ?AccountInterface
            {
                return $this->account;
            }

            public function set(?AccountInterface $account): void
            {
                $this->account = $account;
            }
        };
    }

    private function principal(): LocalOperatorPrincipal
    {
        return LocalOperatorPrincipal::forLocalStdioTransport(
            ['environment' => 'local'],
            LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
            null,
            'cli',
        );
    }

    #[Test]
    public function installing_the_local_operator_as_the_ambient_account_is_refused(): void
    {
        $inner = $this->inner();
        $guard = new LocalOperatorAccountContextGuard($inner);

        try {
            $guard->set($this->principal());
            self::fail('Installing the local operator into the account context must be refused (R-4).');
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('R-4', $refusal->row);
            self::assertStringContainsString('AccountContext', $refusal->getMessage());
        }

        self::assertNull($inner->current(), 'The refusal must not leave the principal installed.');
    }

    /**
     * The refusal must be an exception, not a downgrade. This is the property
     * D-6 states explicitly: "a silent downgrade to anonymous is not an
     * acceptable implementation of any row".
     */
    #[Test]
    public function the_refusal_is_not_a_silent_downgrade_to_anonymous(): void
    {
        $inner = $this->inner();
        $anonymousLike = new class implements AccountInterface {
            public function id(): int
            {
                return 0;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            /** @return string[] */
            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }
        };
        $inner->set($anonymousLike);

        $guard = new LocalOperatorAccountContextGuard($inner);
        try {
            $guard->set($this->principal());
        } catch (LocalOperatorRefusal) {
            // expected
        }

        self::assertSame($anonymousLike, $inner->current(), 'The prior account must be untouched.');
    }

    /**
     * R-4 on the READ side: wrapping a context that ALREADY holds the
     * principal must not hand it back.
     *
     * A guard over `set()` alone is a guard over the wrong half. `current()`
     * is what `EntityRepository::resolveActor()` consumes, and it is the read
     * that turns the string sentinel into uid `0`.
     */
    #[Test]
    public function reading_back_a_principal_that_was_already_installed_is_refused(): void
    {
        $inner = $this->inner();
        $inner->set($this->principal());

        $guard = new LocalOperatorAccountContextGuard($inner);

        try {
            $guard->current();
            self::fail('Reading a pre-installed principal back must be refused (R-4).');
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('R-4', $refusal->row);
            self::assertStringContainsString('AccountContext', $refusal->getMessage());
        }
    }

    /**
     * R-4 on the read side, reached the other way: the inner context is
     * mutated through a second reference held from before the wrap, so the
     * guard's own `set()` is never called.
     */
    #[Test]
    public function a_principal_installed_through_a_second_reference_is_refused_on_read(): void
    {
        $inner = $this->inner();
        $guard = new LocalOperatorAccountContextGuard($inner);

        self::assertNull($guard->current(), 'clean to start with');

        // Someone else still holds $inner and writes straight to it.
        $secondReference = $inner;
        $secondReference->set($this->principal());

        try {
            $guard->current();
            self::fail('A principal installed through a second reference must still be refused on read (R-4).');
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('R-4', $refusal->row);
        }
    }

    /**
     * The refusal is not a downgrade on the read side either: it throws
     * instead of quietly returning null, which would read as "no acting
     * context" and is exactly the silent substitution D-6 forbids.
     */
    #[Test]
    public function the_read_refusal_is_not_a_silent_null(): void
    {
        $inner = $this->inner();
        $inner->set($this->principal());
        $guard = new LocalOperatorAccountContextGuard($inner);

        $threw = false;
        try {
            $result = $guard->current();
            self::assertNotNull($result, 'a silent null is the downgrade R-4 forbids');
        } catch (LocalOperatorRefusal) {
            $threw = true;
        }

        self::assertTrue($threw, 'current() must throw, not return null and not return the principal.');
    }

    #[Test]
    public function the_guard_is_transparent_for_every_other_account(): void
    {
        $inner = $this->inner();
        $guard = new LocalOperatorAccountContextGuard($inner);
        $account = new class implements AccountInterface {
            public function id(): int
            {
                return 7;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            /** @return string[] */
            public function getRoles(): array
            {
                return ['editor'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };

        $guard->set($account);
        self::assertSame($account, $guard->current());
        self::assertSame($account, $inner->current());

        $guard->set(null);
        self::assertNull($guard->current());
    }
}
