<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccessStatus;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Waaseyaa\Access\AccessResult
 * @covers \Waaseyaa\Access\AccessStatus
 */
class AccessResultTest extends TestCase
{
    // ---------------------------------------------------------------
    // Factory methods
    // ---------------------------------------------------------------

    public function testAllowedFactory(): void
    {
        $result = AccessResult::allowed('granted');

        $this->assertTrue($result->isAllowed());
        $this->assertFalse($result->isForbidden());
        $this->assertFalse($result->isNeutral());
        $this->assertSame(AccessStatus::ALLOWED, $result->status);
        $this->assertSame('granted', $result->reason);
    }

    public function testNeutralFactory(): void
    {
        $result = AccessResult::neutral('no opinion');

        $this->assertTrue($result->isNeutral());
        $this->assertFalse($result->isAllowed());
        $this->assertFalse($result->isForbidden());
        $this->assertSame(AccessStatus::NEUTRAL, $result->status);
        $this->assertSame('no opinion', $result->reason);
    }

    public function testForbiddenFactory(): void
    {
        $result = AccessResult::forbidden('denied');

        $this->assertTrue($result->isForbidden());
        $this->assertFalse($result->isAllowed());
        $this->assertFalse($result->isNeutral());
        $this->assertSame(AccessStatus::FORBIDDEN, $result->status);
        $this->assertSame('denied', $result->reason);
    }

    public function testUnauthenticatedFactory(): void
    {
        $result = AccessResult::unauthenticated('no identity');

        $this->assertTrue($result->isUnauthenticated());
        $this->assertFalse($result->isAllowed());
        $this->assertFalse($result->isForbidden());
        $this->assertFalse($result->isNeutral());
        $this->assertSame(AccessStatus::UNAUTHENTICATED, $result->status);
        $this->assertSame('no identity', $result->reason);
    }

    public function testDefaultReasonIsEmpty(): void
    {
        $this->assertSame('', AccessResult::allowed()->reason);
        $this->assertSame('', AccessResult::neutral()->reason);
        $this->assertSame('', AccessResult::forbidden()->reason);
        $this->assertSame('', AccessResult::unauthenticated()->reason);
    }

    // ---------------------------------------------------------------
    // andIf combinations
    // ---------------------------------------------------------------

    /**
     * @dataProvider andIfProvider
     */
    public function testAndIf(AccessResult $a, AccessResult $b, AccessStatus $expected): void
    {
        $combined = $a->andIf($b);
        $this->assertSame($expected, $combined->status);
    }

    /**
     * @return iterable<string, array{AccessResult, AccessResult, AccessStatus}>
     */
    public static function andIfProvider(): iterable
    {
        yield 'allowed AND allowed = allowed' => [
            AccessResult::allowed(),
            AccessResult::allowed(),
            AccessStatus::ALLOWED,
        ];

        yield 'allowed AND neutral = neutral' => [
            AccessResult::allowed(),
            AccessResult::neutral(),
            AccessStatus::NEUTRAL,
        ];

        yield 'allowed AND forbidden = forbidden' => [
            AccessResult::allowed(),
            AccessResult::forbidden(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'neutral AND allowed = neutral' => [
            AccessResult::neutral(),
            AccessResult::allowed(),
            AccessStatus::NEUTRAL,
        ];

        yield 'neutral AND neutral = neutral' => [
            AccessResult::neutral(),
            AccessResult::neutral(),
            AccessStatus::NEUTRAL,
        ];

        yield 'neutral AND forbidden = forbidden' => [
            AccessResult::neutral(),
            AccessResult::forbidden(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'forbidden AND allowed = forbidden' => [
            AccessResult::forbidden(),
            AccessResult::allowed(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'forbidden AND neutral = forbidden' => [
            AccessResult::forbidden(),
            AccessResult::neutral(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'forbidden AND forbidden = forbidden' => [
            AccessResult::forbidden(),
            AccessResult::forbidden(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'unauthenticated AND allowed = unauthenticated' => [
            AccessResult::unauthenticated(),
            AccessResult::allowed(),
            AccessStatus::UNAUTHENTICATED,
        ];

        yield 'allowed AND unauthenticated = unauthenticated' => [
            AccessResult::allowed(),
            AccessResult::unauthenticated(),
            AccessStatus::UNAUTHENTICATED,
        ];

        yield 'unauthenticated AND neutral = unauthenticated' => [
            AccessResult::unauthenticated(),
            AccessResult::neutral(),
            AccessStatus::UNAUTHENTICATED,
        ];

        yield 'unauthenticated AND forbidden = unauthenticated' => [
            AccessResult::unauthenticated(),
            AccessResult::forbidden(),
            AccessStatus::UNAUTHENTICATED,
        ];

        yield 'forbidden AND unauthenticated = unauthenticated' => [
            AccessResult::forbidden(),
            AccessResult::unauthenticated(),
            AccessStatus::UNAUTHENTICATED,
        ];
    }

    // ---------------------------------------------------------------
    // orIf combinations
    // ---------------------------------------------------------------

    /**
     * @dataProvider orIfProvider
     */
    public function testOrIf(AccessResult $a, AccessResult $b, AccessStatus $expected): void
    {
        $combined = $a->orIf($b);
        $this->assertSame($expected, $combined->status);
    }

    /**
     * @return iterable<string, array{AccessResult, AccessResult, AccessStatus}>
     */
    public static function orIfProvider(): iterable
    {
        yield 'allowed OR allowed = allowed' => [
            AccessResult::allowed(),
            AccessResult::allowed(),
            AccessStatus::ALLOWED,
        ];

        yield 'allowed OR neutral = allowed' => [
            AccessResult::allowed(),
            AccessResult::neutral(),
            AccessStatus::ALLOWED,
        ];

        yield 'allowed OR forbidden = forbidden' => [
            AccessResult::allowed(),
            AccessResult::forbidden(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'neutral OR allowed = allowed' => [
            AccessResult::neutral(),
            AccessResult::allowed(),
            AccessStatus::ALLOWED,
        ];

        yield 'neutral OR neutral = neutral' => [
            AccessResult::neutral(),
            AccessResult::neutral(),
            AccessStatus::NEUTRAL,
        ];

        yield 'neutral OR forbidden = forbidden' => [
            AccessResult::neutral(),
            AccessResult::forbidden(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'forbidden OR allowed = forbidden' => [
            AccessResult::forbidden(),
            AccessResult::allowed(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'forbidden OR neutral = forbidden' => [
            AccessResult::forbidden(),
            AccessResult::neutral(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'forbidden OR forbidden = forbidden' => [
            AccessResult::forbidden(),
            AccessResult::forbidden(),
            AccessStatus::FORBIDDEN,
        ];

        yield 'unauthenticated OR allowed = unauthenticated' => [
            AccessResult::unauthenticated(),
            AccessResult::allowed(),
            AccessStatus::UNAUTHENTICATED,
        ];

        yield 'allowed OR unauthenticated = unauthenticated' => [
            AccessResult::allowed(),
            AccessResult::unauthenticated(),
            AccessStatus::UNAUTHENTICATED,
        ];

        yield 'unauthenticated OR neutral = unauthenticated' => [
            AccessResult::unauthenticated(),
            AccessResult::neutral(),
            AccessStatus::UNAUTHENTICATED,
        ];

        yield 'unauthenticated OR forbidden = unauthenticated' => [
            AccessResult::unauthenticated(),
            AccessResult::forbidden(),
            AccessStatus::UNAUTHENTICATED,
        ];

        yield 'forbidden OR unauthenticated = unauthenticated' => [
            AccessResult::forbidden(),
            AccessResult::unauthenticated(),
            AccessStatus::UNAUTHENTICATED,
        ];
    }

    // ---------------------------------------------------------------
    // Reason preservation
    // ---------------------------------------------------------------

    public function testAndIfPreservesWinningReason(): void
    {
        $a = AccessResult::forbidden('you shall not pass');
        $b = AccessResult::allowed('go ahead');

        $this->assertSame('you shall not pass', $a->andIf($b)->reason);
    }

    public function testOrIfPreservesForbiddenReason(): void
    {
        $a = AccessResult::allowed('welcome');
        $b = AccessResult::forbidden('blocked');

        $this->assertSame('blocked', $a->orIf($b)->reason);
    }

    public function testOrIfPreservesAllowedReason(): void
    {
        $a = AccessResult::neutral('meh');
        $b = AccessResult::allowed('policy says yes');

        $this->assertSame('policy says yes', $a->orIf($b)->reason);
    }

    // ---------------------------------------------------------------
    // Chaining
    // ---------------------------------------------------------------

    public function testChainingMultipleAndIf(): void
    {
        $result = AccessResult::allowed()
            ->andIf(AccessResult::allowed())
            ->andIf(AccessResult::allowed());

        $this->assertTrue($result->isAllowed());
    }

    public function testChainingMultipleOrIf(): void
    {
        $result = AccessResult::neutral()
            ->orIf(AccessResult::neutral())
            ->orIf(AccessResult::allowed());

        $this->assertTrue($result->isAllowed());
    }

    public function testChainingMixed(): void
    {
        // (neutral OR allowed) AND forbidden = forbidden
        $result = AccessResult::neutral()
            ->orIf(AccessResult::allowed())
            ->andIf(AccessResult::forbidden('nope'));

        $this->assertTrue($result->isForbidden());
        $this->assertSame('nope', $result->reason);
    }

    // ---------------------------------------------------------------
    // Immutability
    // ---------------------------------------------------------------

    public function testImmutability(): void
    {
        $a = AccessResult::allowed('first');
        $b = AccessResult::forbidden('second');

        $combined = $a->andIf($b);

        // Original values are unchanged.
        $this->assertTrue($a->isAllowed());
        $this->assertSame('first', $a->reason);
        $this->assertTrue($b->isForbidden());
        $this->assertSame('second', $b->reason);
        $this->assertTrue($combined->isForbidden());
    }

    // ---------------------------------------------------------------
    // AccessStatus enum
    // ---------------------------------------------------------------

    public function testAccessStatusValues(): void
    {
        $this->assertSame('allowed', AccessStatus::ALLOWED->value);
        $this->assertSame('neutral', AccessStatus::NEUTRAL->value);
        $this->assertSame('forbidden', AccessStatus::FORBIDDEN->value);
        $this->assertSame('unauthenticated', AccessStatus::UNAUTHENTICATED->value);
    }

    public function testAccessStatusFromString(): void
    {
        $this->assertSame(AccessStatus::ALLOWED, AccessStatus::from('allowed'));
        $this->assertSame(AccessStatus::NEUTRAL, AccessStatus::from('neutral'));
        $this->assertSame(AccessStatus::FORBIDDEN, AccessStatus::from('forbidden'));
        $this->assertSame(AccessStatus::UNAUTHENTICATED, AccessStatus::from('unauthenticated'));
    }
}
