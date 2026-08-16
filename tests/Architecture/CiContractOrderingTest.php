<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fast-contracts-gate-the-long-jobs ordering
 * (docs/specs/governed-gates.md §5): the ~30s support/s1-contract job is a
 * `needs:` prerequisite of the three long test jobs, so a stale recorded
 * roster is one fast red job with a precise message — never the same failure
 * re-reported by unit, random-order, and coverage minutes later (the PR #2399
 * failure shape).
 */
#[CoversNothing]
final class CiContractOrderingTest extends TestCase
{
    #[Test]
    public function long_test_jobs_wait_for_the_fast_contract_job(): void
    {
        $ci = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        foreach (['ci-unit-tests', 'ci-random-order', 'ci-coverage'] as $job) {
            $this->assertSame(
                1,
                preg_match(
                    '/^  ' . preg_quote($job, '/') . ':\n(?:.*\n)*?    needs: support-contract$/m',
                    $ci,
                ),
                sprintf('Job %s must declare `needs: support-contract` so contract failures fail once, fast.', $job),
            );
        }
    }
}
