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
        self::assertStringNotContainsString('--shard=', $this->workflow);
        self::assertStringNotContainsString('--only=', $this->workflow);
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
