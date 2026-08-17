<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CiSingleExecutionProofTest extends TestCase
{
    #[Test]
    public function pullRequestProofRunsOneTimingBalancedCoverageExecution(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);

        self::assertStringContainsString('cancel-in-progress: ${{ github.event_name == \'pull_request\' }}', $workflow);
        self::assertStringContainsString('prepare-test-plan:', $workflow);
        self::assertStringContainsString('php bin/build-phpunit-shards', $workflow);
        self::assertStringContainsString('ci-test-shards:', $workflow);
        self::assertStringContainsString('strategy:', $workflow);
        self::assertStringContainsString('id: [1, 2, 3, 4]', $workflow);
        self::assertStringContainsString('name: phpunit-shard-plan', $workflow);
        self::assertStringContainsString('--coverage-clover build/logs/clover-${{ matrix.id }}.xml', $workflow);
        self::assertStringContainsString('--log-junit build/logs/junit-${{ matrix.id }}.xml', $workflow);
        self::assertStringContainsString('php bin/merge-clover-coverage', $workflow);

        $coverageJob = $this->job($workflow, 'ci-coverage', 'mutation-pilot');
        self::assertStringNotContainsString('vendor/bin/phpunit', $coverageJob);
        $unitGate = $this->job($workflow, 'ci-unit-tests', 'ci-random-order');
        self::assertStringNotContainsString('vendor/bin/phpunit', $unitGate);
        self::assertStringContainsString('needs: [ci-test-shards]', $unitGate);
    }

    private function job(string $workflow, string $name, string $next): string
    {
        $start = strpos($workflow, "  {$name}:");
        $end = strpos($workflow, "  {$next}:", $start === false ? 0 : $start + 1);
        self::assertNotFalse($start, $name);
        self::assertNotFalse($end, $next);

        return substr($workflow, (int) $start, (int) $end - (int) $start);
    }
}
