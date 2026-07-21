<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Complete, immutable policy composition for one contextual type/bundle read.
 *
 * @internal
 */
final class ContextualProtectedEntityReadPlan
{
    private readonly object $evaluationIssuer;

    /** @var \Closure(object, int, object): ContextualProtectedReadEvaluation */
    private readonly \Closure $evaluationFactory;

    /**
     * @param list<array{policy: ProtectedEntityReadPolicyInterface, inputs: list<string>}> $policies
     */
    public function __construct(
        public readonly string $entityTypeId,
        public readonly string $bundle,
        public readonly object $authorizationBoundary,
        public readonly array $authorizationInputs,
        public readonly array $contextKeys,
        private readonly array $policies,
    ) {
        $this->evaluationIssuer = new \stdClass();
        $factory = \Closure::bind(
            static fn(object $boundary, int $evaluatedAt, object $issuer): ContextualProtectedReadEvaluation => new ContextualProtectedReadEvaluation(
                $boundary,
                $evaluatedAt,
                $issuer,
            ),
            null,
            ContextualProtectedReadEvaluation::class,
        );
        $this->evaluationFactory = $factory;
    }

    /** @internal */
    public function beginEvaluation(object $authorizationBoundary, int $evaluatedAt): ContextualProtectedReadEvaluation
    {
        return ($this->evaluationFactory)($authorizationBoundary, $evaluatedAt, $this->evaluationIssuer);
    }

    /** @internal */
    public function closeEvaluation(ContextualProtectedReadEvaluation $evaluation): void
    {
        $evaluation->close($this->evaluationIssuer);
    }

    /**
     * @param list<ContextualProtectedReadCandidate> $candidates
     * @return array<string, AccessResult>
     */
    public function accessBatch(
        AuthorizationPrincipalInterface $principal,
        array $candidates,
        ContextualProtectedReadEvaluation $evaluation,
        string $operation = 'view',
        ?string $requiredContextKey = null,
    ): array {
        $keys = [];
        foreach ($candidates as $candidate) {
            if (isset($keys[$candidate->key])) {
                return $this->denyAll($candidates, 'Contextual candidate keys must be unique.');
            }
            $keys[$candidate->key] = true;
        }
        if (!$evaluation->isActiveFor($this->evaluationIssuer)
            || $evaluation->authorizationBoundary !== $this->authorizationBoundary
        ) {
            return $this->denyAll($candidates, 'Contextual authorization boundary mismatch.');
        }

        if ($requiredContextKey !== null && !in_array($requiredContextKey, $this->contextKeys, true)) {
            return $this->denyAll($candidates, 'The required contextual policy is absent.');
        }

        $decisions = [];
        foreach ($candidates as $candidate) {
            $decisions[$candidate->key] = AccessResult::neutral('No protected entity-read policy provided an opinion.');
        }
        $requiredDecisions = null;

        foreach ($this->policies as $entry) {
            $policy = $entry['policy'];
            if ($policy instanceof ContextualProtectedEntityReadPolicyInterface) {
                try {
                    $policyResults = $policy->accessBatch($principal, $candidates, $evaluation, $operation);
                } catch (\Throwable) {
                    return $this->denyAll($candidates, 'Contextual entity-read policy evaluation failed.');
                }
                if (array_keys($policyResults) !== array_keys($decisions)) {
                    return $this->denyAll($candidates, 'Contextual entity-read policy returned an incomplete result set.');
                }
                foreach ($policyResults as $key => $result) {
                    if (!$result instanceof AccessResult) {
                        return $this->denyAll($candidates, 'Contextual entity-read policy returned an invalid result.');
                    }
                    $decisions[$key] = $decisions[$key]->orIf($result);
                }
                if ($policy->contextKey() === $requiredContextKey) {
                    $requiredDecisions = $policyResults;
                }
                continue;
            }

            foreach ($candidates as $candidate) {
                $values = [];
                foreach ($entry['inputs'] as $field) {
                    if (!in_array($field, $candidate->subject->fields(), true)) {
                        $decisions[$candidate->key] = AccessResult::forbidden(
                            sprintf('Protected entity-read subject is missing required field "%s".', $field),
                        );
                        continue 2;
                    }
                    $values[$field] = $candidate->subject->get($field);
                }
                $decisions[$candidate->key] = $decisions[$candidate->key]->orIf($policy->access(
                    $principal,
                    $candidate->structure,
                    new CompiledPolicySubjectView($values),
                    $operation,
                ));
            }
        }

        if ($requiredContextKey !== null) {
            if ($requiredDecisions === null) {
                return $this->denyAll($candidates, 'The required contextual policy did not evaluate.');
            }
            foreach ($candidates as $candidate) {
                $required = $requiredDecisions[$candidate->key] ?? null;
                if (!$required instanceof AccessResult || !$required->isAllowed()) {
                    $decisions[$candidate->key] = AccessResult::forbidden(
                        'The required contextual policy did not allow this candidate.',
                    );
                }
            }
        }

        return $decisions;
    }

    /** @param list<ContextualProtectedReadCandidate> $candidates @return array<string, AccessResult> */
    private function denyAll(array $candidates, string $reason): array
    {
        $result = [];
        foreach ($candidates as $candidate) {
            $result[$candidate->key] = AccessResult::forbidden($reason);
        }

        return $result;
    }
}
