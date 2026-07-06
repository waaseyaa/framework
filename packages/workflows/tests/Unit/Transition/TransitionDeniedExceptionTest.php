<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Transition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;

/**
 * @covers \Waaseyaa\Workflows\Transition\TransitionDeniedException
 */
#[CoversClass(TransitionDeniedException::class)]
final class TransitionDeniedExceptionTest extends TestCase
{
    #[Test]
    public function it_exposes_a_machine_readable_reason_and_message(): void
    {
        $exception = new TransitionDeniedException(
            TransitionDeniedException::REASON_PERMISSION,
            "Account lacks permission 'use editorial transition publish'.",
        );

        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $exception->reason);
        $this->assertSame("Account lacks permission 'use editorial transition publish'.", $exception->getMessage());
    }

    #[Test]
    public function it_is_a_runtime_exception(): void
    {
        $exception = new TransitionDeniedException(TransitionDeniedException::REASON_UNBOUND, 'unbound');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    #[Test]
    public function the_reason_vocabulary_is_exactly_four_values(): void
    {
        $this->assertSame('unbound', TransitionDeniedException::REASON_UNBOUND);
        $this->assertSame('unknown_transition', TransitionDeniedException::REASON_UNKNOWN_TRANSITION);
        $this->assertSame('illegal_edge', TransitionDeniedException::REASON_ILLEGAL_EDGE);
        $this->assertSame('permission', TransitionDeniedException::REASON_PERMISSION);
    }
}
