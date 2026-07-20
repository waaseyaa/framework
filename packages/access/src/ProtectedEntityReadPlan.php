<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityStructure;

/**
 * Complete immutable Protected entity-read evaluator for one exact type/bundle.
 *
 * The plan contains every matching V2 entity-read policy resolved by
 * EntityAccessHandler. It deliberately carries no subject values and exposes
 * no policy internals.
 *
 * @internal
 */
final readonly class ProtectedEntityReadPlan
{
    /**
     * @param non-empty-list<array{policy: ProtectedEntityReadPolicyInterface, inputs: list<string>}> $policies
     * @param list<string> $authorizationInputs
     */
    public function __construct(
        public string $entityTypeId,
        public string $bundleId,
        public array $authorizationInputs,
        private array $policies,
    ) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        if ($structure->entityTypeId !== $this->entityTypeId || $structure->bundleId !== $this->bundleId) {
            return AccessResult::forbidden('Protected entity-read plan does not match the projected subject.');
        }

        $result = AccessResult::neutral('No protected entity-read policy provided an opinion.');
        foreach ($this->policies as $spec) {
            $policy = $spec['policy'];
            if ($policy instanceof ClassifiedProtectedEntityReadPolicyInterface && $operation !== 'view') {
                continue;
            }
            $values = [];
            foreach ($spec['inputs'] as $field) {
                if (!in_array($field, $subject->fields(), true)) {
                    return AccessResult::forbidden(sprintf('Protected entity-read projection is missing required field "%s".', $field));
                }
                $values[$field] = $subject->get($field);
            }
            $result = $result->orIf($policy->access($principal, $structure, new CompiledPolicySubjectView($values), $operation));
            if ($result->isForbidden()) {
                return $result;
            }
        }

        return $result;
    }

    public function cacheDimension(): string
    {
        return hash('xxh128', json_encode(array_map(
            static fn(array $spec): array => [
                $spec['policy']::class,
                spl_object_id($spec['policy']),
                $spec['inputs'],
            ],
            $this->policies,
        ), JSON_THROW_ON_ERROR));
    }
}
