<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Validator\ContradictionChecker;

#[CoversClass(ContradictionChecker::class)]
final class ContradictionCheckerTest extends TestCase
{
    private ContradictionChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new ContradictionChecker();
    }

    #[Test]
    public function no_contradictions_returns_max_score(): void
    {
        $result = $this->checker->check(
            modelOutput: 'Use EntityBase for all entities.',
            contextFacts: ['EntityBase provides the base class'],
        );

        $this->assertSame(20.0, $result['score']);
        $this->assertEmpty($result['issues']);
    }

    #[Test]
    public function detected_contradiction_reduces_score(): void
    {
        $result = $this->checker->check(
            modelOutput: 'You can use getter methods to access the entity.',
            contextFacts: ['EntityEvent has no getter methods'],
        );

        $this->assertLessThan(20.0, $result['score']);
        $this->assertNotEmpty($result['issues']);
    }

    #[Test]
    public function not_pattern_detected(): void
    {
        $result = $this->checker->check(
            modelOutput: 'Call interface{} in your code.',
            contextFacts: ['Use any not interface{}'],
        );

        $this->assertSame(15.0, $result['score']); // 20 - 5
        $this->assertCount(1, $result['issues']);
    }

    #[Test]
    public function no_pattern_detected(): void
    {
        $result = $this->checker->check(
            modelOutput: 'The system supports magic numbers like 42.',
            contextFacts: ['There are no magic numbers allowed'],
        );

        $this->assertSame(15.0, $result['score']); // 20 - 5
        $this->assertCount(1, $result['issues']);
    }

    #[Test]
    public function many_contradictions_clamp_to_zero(): void
    {
        // Output contains: getter, interface, direct, magic, psr
        // Each fact must negatively match the output
        $result = $this->checker->check(
            modelOutput: 'use getter methods, interface calls, direct database, magic numbers, psr logging.',
            contextFacts: [
                'There are no getter methods allowed',
                'There is no interface allowed',
                'There is no direct database access',
                'There are no magic numbers allowed',
                'There is no psr logging allowed',
            ],
        );

        $this->assertSame(0.0, $result['score']);
    }

    #[Test]
    public function empty_context_facts_return_max_score(): void
    {
        $result = $this->checker->check(
            modelOutput: 'Some arbitrary output.',
            contextFacts: [],
        );

        $this->assertSame(20.0, $result['score']);
        $this->assertEmpty($result['issues']);
    }

    #[Test]
    public function each_fact_only_penalized_once(): void
    {
        // A fact with two matching negated terms — should only penalize once per fact
        $result = $this->checker->check(
            modelOutput: 'Use getter methods.',
            contextFacts: ['There are no getter methods allowed'],
        );

        $this->assertSame(15.0, $result['score']); // 20 - 5 (only once)
    }
}
