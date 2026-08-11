<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Upgrade;

/**
 * Evaluates one versioned upgrade observation without reading or writing state.
 *
 * @api
 */
final class UpgradePreflightEvaluator
{
    /** @var array<string, int> */
    private const array PRECEDENCE = [
        'ready' => 0,
        'blocked' => 1,
        'unsupported' => 2,
        'invalid' => 3,
    ];

    /** @param array<string, mixed> $observation */
    public function evaluateBundled(array $observation): UpgradePreflightResult
    {
        return $this->evaluate(UpgradePreflightContract::load(), $observation);
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $observation
     */
    public function evaluate(array $contract, array $observation): UpgradePreflightResult
    {
        if (!$this->contractIsValid($contract)) {
            return $this->invalid('CONTRACT_INVALID');
        }

        /** @var list<string> $evaluationOrder */
        $evaluationOrder = $contract['evaluation_order'];
        /** @var array<string, mixed> $schema */
        $schema = $contract['observation_schema'];

        $shapeFailure = $this->observationShapeFailure($schema, $observation);
        if ($shapeFailure !== null) {
            return new UpgradePreflightResult(
                UpgradePreflightDecision::Invalid,
                [$shapeFailure],
                ['observation'],
            );
        }

        if ($observation['schema_version'] !== $contract['schema_version']) {
            return $this->invalid('OBSERVATION_SCHEMA_UNSUPPORTED');
        }
        if ($observation['transition_id'] !== $contract['transition_id']) {
            return $this->invalid('OBSERVATION_TRANSITION_MISMATCH');
        }

        $decision = UpgradePreflightDecision::Ready;
        $reasons = [];
        /** @var list<array<string, mixed>> $rules */
        $rules = $contract['rules'];
        foreach ($rules as $rule) {
            $value = $this->valueAt($observation, (string) $rule['path']);
            if ($this->rulePasses((string) $rule['operator'], $value, $rule['expected'])) {
                continue;
            }

            $candidate = UpgradePreflightDecision::from((string) $rule['decision']);
            if (self::PRECEDENCE[$candidate->value] > self::PRECEDENCE[$decision->value]) {
                $decision = $candidate;
            }

            $reason = (string) $rule['reason'];
            if (!in_array($reason, $reasons, true)) {
                $reasons[] = $reason;
            }
        }

        return new UpgradePreflightResult($decision, $reasons, $evaluationOrder);
    }

    /** @param array<string, mixed> $contract */
    private function contractIsValid(array $contract): bool
    {
        $keys = [
            'schema_version',
            'contract_id',
            'transition_id',
            'evaluation_order',
            'observation_schema',
            'rules',
            'failure_policy',
        ];
        if (!$this->hasExactKeys($contract, $keys)) {
            return false;
        }
        if ($contract['schema_version'] !== 1
            || !is_string($contract['contract_id'])
            || !is_string($contract['transition_id'])
            || !is_array($contract['evaluation_order'])
            || !is_array($contract['observation_schema'])
            || !is_array($contract['rules'])
            || !is_array($contract['failure_policy'])
        ) {
            return false;
        }

        $order = $contract['evaluation_order'];
        if ($order !== ['observation', 'source', 'target', 'platform', 'configuration', 'schema', 'operations']) {
            return false;
        }

        $schema = $contract['observation_schema'];
        if (!$this->hasExactKeys($schema, ['root_keys', 'gate_keys'])
            || !is_array($schema['root_keys'])
            || !is_array($schema['gate_keys'])
        ) {
            return false;
        }

        $lastGate = 0;
        foreach ($contract['rules'] as $rule) {
            if (!is_array($rule)
                || !$this->hasExactKeys($rule, ['gate', 'path', 'operator', 'expected', 'decision', 'reason'])
                || !is_string($rule['gate'])
                || !is_string($rule['path'])
                || !is_string($rule['operator'])
                || !is_string($rule['decision'])
                || !is_string($rule['reason'])
                || !in_array($rule['operator'], ['equals', 'empty', 'sha256'], true)
                || !in_array($rule['decision'], ['blocked', 'unsupported'], true)
                || !preg_match('/^[A-Z][A-Z0-9_]+$/', $rule['reason'])
            ) {
                return false;
            }

            $gateIndex = array_search($rule['gate'], $order, true);
            if (!is_int($gateIndex) || $gateIndex < 1 || $gateIndex < $lastGate) {
                return false;
            }
            $lastGate = $gateIndex;

            if (!str_starts_with($rule['path'], $rule['gate'] . '.')) {
                return false;
            }
            [$gate, $key] = explode('.', $rule['path'], 2);
            if (!isset($schema['gate_keys'][$gate])
                || !is_array($schema['gate_keys'][$gate])
                || !in_array($key, $schema['gate_keys'][$gate], true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $observation
     */
    private function observationShapeFailure(array $schema, array $observation): ?string
    {
        /** @var list<string> $rootKeys */
        $rootKeys = $schema['root_keys'];
        $unknownRoot = array_diff(array_keys($observation), $rootKeys);
        if ($unknownRoot !== []) {
            return 'OBSERVATION_UNKNOWN_KEY';
        }
        if (array_diff($rootKeys, array_keys($observation)) !== []) {
            return 'OBSERVATION_MISSING_KEY';
        }

        /** @var array<string, list<string>> $gateKeys */
        $gateKeys = $schema['gate_keys'];
        foreach ($gateKeys as $gate => $expectedKeys) {
            $value = $observation[$gate] ?? null;
            if (!is_array($value) || array_is_list($value)) {
                return 'OBSERVATION_TYPE_INVALID';
            }
            if (array_diff(array_keys($value), $expectedKeys) !== []) {
                return 'OBSERVATION_UNKNOWN_KEY';
            }
            if (array_diff($expectedKeys, array_keys($value)) !== []) {
                return 'OBSERVATION_MISSING_KEY';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $observation */
    private function valueAt(array $observation, string $path): mixed
    {
        [$gate, $key] = explode('.', $path, 2);
        $gateValue = $observation[$gate];
        assert(is_array($gateValue));

        return $gateValue[$key];
    }

    private function rulePasses(string $operator, mixed $value, mixed $expected): bool
    {
        return match ($operator) {
            'equals' => $value === $expected,
            'empty' => is_array($value) && $value === [],
            'sha256' => is_string($value) && preg_match('/^sha256:[0-9a-f]{64}$/', $value) === 1,
            default => false,
        };
    }

    /** @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        return array_diff(array_keys($value), $expected) === []
            && array_diff($expected, array_keys($value)) === [];
    }

    private function invalid(string $reason): UpgradePreflightResult
    {
        return new UpgradePreflightResult(
            UpgradePreflightDecision::Invalid,
            [$reason],
            ['observation'],
        );
    }
}
