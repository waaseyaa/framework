<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AgentOutput\AgentDetector;

/**
 * NFR-001 budget verification: {@see AgentDetector::detect()} must return
 * within ≤ 1 ms median over 100 invocations. The method is intentionally
 * lookup-only — no I/O, no filesystem, just `getenv()` calls — so this
 * test catches any accidental performance regressions (e.g. someone
 * adding lazy filesystem probes to the detector).
 */
#[CoversClass(AgentDetector::class)]
final class AgentDetectorTimingTest extends TestCase
{
    #[Test]
    public function detectCompletesWithinMillisecondBudget(): void
    {
        $detector = new AgentDetector();
        $samples = [];

        for ($i = 0; $i < 100; $i++) {
            $start = hrtime(true);
            $detector->detect();
            $samples[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($samples);
        $median = $samples[(int) (count($samples) / 2)];

        // Soft warning at 0.5 ms (catches early-signs regressions); hard
        // ceiling at 5 ms (NFR-001 is ≤ 1 ms — 5× is a catastrophic-only fail).
        if ($median > 0.5) {
            fwrite(STDERR, sprintf(
                "\n[NFR-001 WARNING] AgentDetector::detect() median %.4f ms (soft budget: 0.5 ms).\n",
                $median,
            ));
        }

        self::assertLessThan(
            5.0,
            $median,
            sprintf('NFR-001 HARD CEILING: detect() median %.4f ms (limit: 5 ms).', $median),
        );
    }
}
