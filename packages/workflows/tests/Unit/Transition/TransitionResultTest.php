<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Transition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\Transition\TransitionResult;

/**
 * @covers \Waaseyaa\Workflows\Transition\TransitionResult
 */
#[CoversClass(TransitionResult::class)]
final class TransitionResultTest extends TestCase
{
    #[Test]
    public function it_carries_the_transition_outcome(): void
    {
        $result = new TransitionResult(fromState: 'draft', toState: 'published', transitionId: 'publish');

        $this->assertSame('draft', $result->fromState);
        $this->assertSame('published', $result->toState);
        $this->assertSame('publish', $result->transitionId);
    }
}
