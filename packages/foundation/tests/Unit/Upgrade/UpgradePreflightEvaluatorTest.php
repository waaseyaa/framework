<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Upgrade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Upgrade\UpgradePreflightEvaluator;

#[CoversClass(UpgradePreflightEvaluator::class)]
final class UpgradePreflightEvaluatorTest extends TestCase
{
    #[Test]
    public function it_evaluates_the_versioned_transition_without_mutating_evidence(): void
    {
        self::assertTrue(
            class_exists(UpgradePreflightEvaluator::class),
            'The fail-closed upgrade preflight evaluator does not exist yet.',
        );

        $root = dirname(__DIR__, 5);
        $contract = $this->decode($root . '/support/upgrade/s1-v1.json');
        $expected = [
            'ready.json' => ['ready', []],
            'mixed-source.json' => ['unsupported', ['SOURCE_PACKAGE_SET_MIXED']],
            'unknown-source.json' => ['unsupported', ['SOURCE_TAG_UNSUPPORTED', 'SOURCE_COMMIT_UNSUPPORTED']],
            'config-drift.json' => ['blocked', ['CONFIG_DRIFT_PRESENT']],
            'schema-unknown.json' => ['blocked', ['SCHEMA_LEDGER_UNKNOWN', 'LIVE_SCHEMA_UNVERIFIED']],
            'missing-recovery-proof.json' => ['blocked', ['RESTORE_PROOF_MISSING']],
            'unknown-key.json' => ['invalid', ['OBSERVATION_UNKNOWN_KEY']],
        ];

        $evaluator = new UpgradePreflightEvaluator();
        foreach ($expected as $fixture => [$decision, $reasons]) {
            $observation = $this->decode($root . '/tests/Fixtures/UpgradePreflight/' . $fixture);
            $contractBefore = $contract;
            $observationBefore = $observation;

            $result = $evaluator->evaluate($contract, $observation);

            self::assertSame($decision, $result->decision->value, $fixture);
            self::assertSame($reasons, $result->reasonCodes, $fixture);
            self::assertSame($contractBefore, $contract, $fixture . ' mutated the contract');
            self::assertSame($observationBefore, $observation, $fixture . ' mutated the observation');
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
