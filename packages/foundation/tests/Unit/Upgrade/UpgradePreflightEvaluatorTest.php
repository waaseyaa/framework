<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Upgrade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Upgrade\UpgradePreflightContract;
use Waaseyaa\Foundation\Upgrade\UpgradePreflightEvaluator;

#[CoversClass(UpgradePreflightContract::class)]
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
        $contract = UpgradePreflightContract::load();
        $expected = [
            'ready.json' => ['ready', []],
            'mixed-source.json' => ['unsupported', ['SOURCE_PACKAGE_SET_MIXED', 'SOURCE_PACKAGE_DIGEST_UNSUPPORTED']],
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

        $bundled = $evaluator->evaluateBundled(
            $this->decode($root . '/tests/Fixtures/UpgradePreflight/ready.json'),
        );
        self::assertSame('ready', $bundled->decision->value);
    }

    #[Test]
    public function it_rejects_malformed_contract_and_observation_envelopes(): void
    {
        $root = dirname(__DIR__, 5);
        $contract = UpgradePreflightContract::load();
        $observation = $this->decode($root . '/tests/Fixtures/UpgradePreflight/ready.json');
        $evaluator = new UpgradePreflightEvaluator();

        $malformedContract = $contract;
        $malformedContract['unknown'] = true;
        self::assertSame(
            ['CONTRACT_INVALID'],
            $evaluator->evaluate($malformedContract, $observation)->reasonCodes,
        );

        $unsupportedSchema = $observation;
        $unsupportedSchema['schema_version'] = 2;
        self::assertSame(
            ['OBSERVATION_SCHEMA_UNSUPPORTED'],
            $evaluator->evaluate($contract, $unsupportedSchema)->reasonCodes,
        );

        $wrongTransition = $observation;
        $wrongTransition['transition_id'] = 'different-transition';
        self::assertSame(
            ['OBSERVATION_TRANSITION_MISMATCH'],
            $evaluator->evaluate($contract, $wrongTransition)->reasonCodes,
        );

        $missingKey = $observation;
        unset($missingKey['operations']);
        self::assertSame(
            ['OBSERVATION_MISSING_KEY'],
            $evaluator->evaluate($contract, $missingKey)->reasonCodes,
        );

        $invalidType = $observation;
        $invalidType['schema'] = [];
        self::assertSame(
            ['OBSERVATION_TYPE_INVALID'],
            $evaluator->evaluate($contract, $invalidType)->reasonCodes,
        );
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
