<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;

/**
 * Mission request-surface-hardening (#1652) WP02 — bearer-token hardening.
 *
 * Pins the constant-time full-scan comparison (FR-005, contract
 * `bearer-and-dbpath.md` §1–4) and the duck-typed blocked-account rejection
 * (FR-006, §5–8). NFR-003 holds by construction: the liveness check is an
 * in-memory `isActive()` call on the already-resolved account object — this
 * test wires no storage or database fixture, and none is needed.
 */
#[CoversClass(BearerTokenAuth::class)]
final class BearerTokenAuthHardeningTest extends TestCase
{
    #[Test]
    public function blockedAccountFailsClosed(): void
    {
        $blocked = $this->accountWithIsActive(false);
        $auth = new BearerTokenAuth(['blocked-token' => $blocked]);

        // SC-003: a token mapped to an inactive account stops authenticating.
        $this->assertNull($auth->authenticate('Bearer blocked-token'));
    }

    #[Test]
    public function activeAccountAuthenticates(): void
    {
        $active = $this->accountWithIsActive(true);
        $auth = new BearerTokenAuth(['active-token' => $active]);

        $this->assertSame($active, $auth->authenticate('Bearer active-token'));
    }

    #[Test]
    public function accountWithoutIsActiveMethodAuthenticates(): void
    {
        // Contract §6: custom AccountInterface implementations without an
        // isActive() method own their liveness semantics — pass through.
        $legacy = $this->plainAccount();
        $auth = new BearerTokenAuth(['legacy-token' => $legacy]);

        $this->assertSame($legacy, $auth->authenticate('Bearer legacy-token'));
    }

    #[Test]
    public function blockedRejectionIsIndistinguishableFromUnknownToken(): void
    {
        $blocked = $this->accountWithIsActive(false);
        $auth = new BearerTokenAuth(['blocked-token' => $blocked]);

        // Contract §5: same null, no exception, no distinct state — the
        // caller-visible outcome offers no blocked-vs-invalid oracle.
        $blockedResult = $auth->authenticate('Bearer blocked-token');
        $unknownResult = $auth->authenticate('Bearer no-such-token');

        $this->assertNull($blockedResult);
        $this->assertNull($unknownResult);
        $this->assertSame($unknownResult, $blockedResult);
    }

    #[Test]
    public function purelyNumericTokenAuthenticates(): void
    {
        // PHP coerces the '12345' map key to int 12345; without the (string)
        // cast before hash_equals() this throws a TypeError (contract §2).
        $account = $this->plainAccount();
        $auth = new BearerTokenAuth(['12345' => $account]);

        $this->assertSame($account, $auth->authenticate('Bearer 12345'));
    }

    #[Test]
    public function firstAndLastMapEntriesBothAuthenticate(): void
    {
        // Pins the no-early-exit full scan's correctness (not its timing):
        // a match at any position of the candidate set must be returned.
        $first = $this->plainAccount();
        $middle = $this->plainAccount();
        $last = $this->plainAccount();
        $auth = new BearerTokenAuth([
            'first-token' => $first,
            'middle-token' => $middle,
            'last-token' => $last,
        ]);

        $this->assertSame($first, $auth->authenticate('Bearer first-token'));
        $this->assertSame($last, $auth->authenticate('Bearer last-token'));
    }

    /**
     * Anonymous class with an isActive() method on top of AccountInterface —
     * createMock() cannot add non-interface methods.
     */
    private function accountWithIsActive(bool $active): AccountInterface
    {
        return new class($active) implements AccountInterface {
            public function __construct(private readonly bool $active) {}

            public function id(): int|string
            {
                return 42;
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
                return true;
            }

            public function isActive(): bool
            {
                return $this->active;
            }
        };
    }

    private function plainAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 7;
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
                return true;
            }
        };
    }
}
