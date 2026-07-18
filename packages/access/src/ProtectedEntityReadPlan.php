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
     * @param non-empty-list<ProjectedProtectedEntityReadPolicyInterface> $policies
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
        foreach ($this->policies as $policy) {
            $result = $result->orIf($policy->access($principal, $structure, $subject, $operation));
            if ($result->isForbidden()) {
                return $result;
            }
        }

        return $result;
    }
}
