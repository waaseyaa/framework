<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class NightlyRandomOrderProofTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/.github/workflows/nightly.yml';
        self::assertFileExists($path);
        $this->workflow = (string) file_get_contents($path);
    }

    #[Test]
    public function it_runs_the_complete_unsharded_suite_on_a_schedule(): void
    {
        self::assertStringContainsString('schedule:', $this->workflow);
        self::assertStringContainsString('workflow_dispatch:', $this->workflow);
        self::assertStringContainsString('composer test:random', $this->workflow);

        // This job is the *only* proof of cross-shard ordering interactions —
        // ci/random-order shards on pull requests and cannot observe them.
        // bin/test-random-order forwards any argument it does not recognise
        // straight through to PHPUnit, so a stray flag here would silently
        // narrow "complete inventory" down to one suite/group/filter without
        // any other signal catching it. Every flag that can narrow what runs
        // is forbidden: --shard= and --only= (shard-plan selection),
        // --plan= (the flag that switches the runner into shard-plan mode at
        // all), and PHPUnit's own --testsuite, --filter, --group, and
        // --exclude-group.
        foreach (['--shard=', '--only=', '--plan=', '--testsuite', '--filter', '--group', '--exclude-group'] as $narrowing) {
            self::assertStringNotContainsString(
                $narrowing,
                $this->workflow,
                "nightly.yml must not narrow the complete-inventory run with {$narrowing}.",
            );
        }
    }

    #[Test]
    public function it_supports_manual_seed_replay_and_guards_concurrency(): void
    {
        self::assertStringContainsString('seed:', $this->workflow);
        self::assertStringContainsString('TEST_RANDOM_SEED', $this->workflow);
        self::assertStringContainsString('concurrency:', $this->workflow);
    }

    #[Test]
    public function it_retains_failure_evidence_and_holds_no_deployment_authority(): void
    {
        self::assertStringContainsString('if: failure()', $this->workflow);
        self::assertStringContainsString('upload-artifact', $this->workflow);

        foreach (['deploy', 'release', 'split', 'packagist', 'rsync', 'ssh'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                strtolower($this->workflow),
                "nightly.yml must hold no {$forbidden} authority.",
            );
        }
    }
}
