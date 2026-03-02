<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityInterface;

/**
 * Checks entity access by running all registered AccessPolicy plugins.
 *
 * Policies are filtered by entity type, then executed. Results are combined
 * using OR logic (any Allowed grants access), but Forbidden always wins.
 * If no policy grants access, the result is Neutral (effectively denied).
 */
class EntityAccessHandler
{
    /**
     * @var AccessPolicyInterface[]
     */
    private array $policies;

    /**
     * @param AccessPolicyInterface[] $policies
     */
    public function __construct(array $policies = [])
    {
        $this->policies = $policies;
    }

    /**
     * Add an access policy.
     */
    public function addPolicy(AccessPolicyInterface $policy): void
    {
        $this->policies[] = $policy;
    }

    /**
     * Check access for an existing entity.
     *
     * @param EntityInterface  $entity    The entity being accessed.
     * @param string           $operation The operation: 'view', 'update', or 'delete'.
     * @param AccountInterface $account   The account requesting access.
     */
    public function check(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $result = AccessResult::neutral('No policy provided an opinion.');
        $entityTypeId = $entity->getEntityTypeId();

        foreach ($this->policies as $policy) {
            if (!$policy->appliesTo($entityTypeId)) {
                continue;
            }

            $policyResult = $policy->access($entity, $operation, $account);
            $result = $result->orIf($policyResult);

            // Short-circuit on Forbidden — nothing can override it.
            if ($result->isForbidden()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Check access for creating a new entity.
     *
     * @param string           $entityTypeId The entity type ID.
     * @param string           $bundle       The bundle.
     * @param AccountInterface $account      The account requesting access.
     */
    public function checkCreateAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        $result = AccessResult::neutral('No policy provided an opinion.');

        foreach ($this->policies as $policy) {
            if (!$policy->appliesTo($entityTypeId)) {
                continue;
            }

            $policyResult = $policy->createAccess($entityTypeId, $bundle, $account);
            $result = $result->orIf($policyResult);

            // Short-circuit on Forbidden — nothing can override it.
            if ($result->isForbidden()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Check access for a specific field on an entity.
     *
     * Only policies implementing FieldAccessPolicyInterface participate.
     * Results are combined using OR logic, with Forbidden short-circuiting.
     *
     * @param EntityInterface  $entity    The entity being accessed.
     * @param string           $fieldName The field name being checked.
     * @param string           $operation The operation: 'view' or 'edit'.
     * @param AccountInterface $account   The account requesting access.
     */
    public function checkFieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        $result = AccessResult::neutral('No field access policy provided an opinion.');
        $entityTypeId = $entity->getEntityTypeId();

        foreach ($this->policies as $policy) {
            if (!$policy->appliesTo($entityTypeId)) {
                continue;
            }
            if (!$policy instanceof FieldAccessPolicyInterface) {
                continue;
            }

            $policyResult = $policy->fieldAccess($entity, $fieldName, $operation, $account);
            $result = $result->orIf($policyResult);

            if ($result->isForbidden()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Filter a list of field names, removing those that are forbidden.
     *
     * @param EntityInterface  $entity     The entity being accessed.
     * @param string[]         $fieldNames The field names to check.
     * @param string           $operation  The operation: 'view' or 'edit'.
     * @param AccountInterface $account    The account requesting access.
     *
     * @return string[] Field names that are not forbidden.
     */
    public function filterFields(
        EntityInterface $entity,
        array $fieldNames,
        string $operation,
        AccountInterface $account,
    ): array {
        return array_values(array_filter(
            $fieldNames,
            fn(string $field): bool => !$this->checkFieldAccess($entity, $field, $operation, $account)->isForbidden(),
        ));
    }
}
