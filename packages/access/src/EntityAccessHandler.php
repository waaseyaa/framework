<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Access\Attribute\AccessPolicy;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Checks entity access by running all registered AccessPolicy plugins.
 *
 * Policies are filtered by entity type and — when the #[AccessPolicy] attribute
 * declares a non-empty bundles list — by bundle. Results are combined using OR
 * logic (any Allowed grants access), but Forbidden always wins. If no policy
 * grants access, the result is Neutral (effectively denied).
 *
 * Recognized operations: 'view', 'update', 'delete', 'translate'. The 'translate'
 * operation falls through to 'update' when no policy expresses an opinion
 * (translate ⊆ update); explicit Forbidden on 'translate' is honored without
 * fallthrough. Policies that implement {@see ContextAwareAccessPolicyInterface}
 * receive a context bag (for 'translate' this carries the target 'langcode').
 *
 * See docs/specs/bundle-scoped-fields.md §Access for the bundle filter contract.
 * @api
 */
class EntityAccessHandler
{
    /**
     * Operations recognized by the handler. 'translate' is the M-006 addition
     * that falls through to 'update' when no policy opines.
     */
    public const array RECOGNIZED_OPERATIONS = [GateInterface::VIEW, GateInterface::UPDATE, GateInterface::DELETE, self::OPERATION_TRANSLATE];

    /** Translate operation identifier — no GateInterface constant; kept local. */
    private const string OPERATION_TRANSLATE = 'translate';
    /**
     * @var AccessPolicyInterface[]
     */
    private array $policies = [];

    /**
     * Bundle filters parallel to $policies. Empty array = applies to every bundle.
     *
     * @var array<int, string[]>
     */
    private array $bundleFilters = [];

    /**
     * @param AccessPolicyInterface[] $policies
     */
    public function __construct(array $policies = [])
    {
        foreach ($policies as $policy) {
            $this->addPolicy($policy);
        }
    }

    /**
     * Add an access policy.
     */
    public function addPolicy(AccessPolicyInterface $policy): void
    {
        $this->policies[] = $policy;
        $this->bundleFilters[] = $this->resolveBundles($policy);
    }

    /**
     * Check access for an existing entity.
     *
     * For the 'translate' operation, when the aggregated result is Neutral (no
     * policy opined), the handler re-invokes itself with 'update' — translate
     * is treated as a subset of update by default. An explicit Forbidden on
     * 'translate' is honored and does NOT fall through to update.
     *
     * Policies implementing {@see ContextAwareAccessPolicyInterface} receive the
     * $context bag; others use the standard {@see AccessPolicyInterface::access()}.
     *
     * @param EntityInterface  $entity    The entity being accessed.
     * @param string           $operation The operation: 'view', 'update', 'delete', or 'translate'.
     * @param AccountInterface $account   The account requesting access.
     * @param array<string, mixed> $context Optional extra context. For 'translate':
     *                                       ['langcode' => string].
     */
    public function check(
        EntityInterface $entity,
        string $operation,
        AccountInterface $account,
        array $context = [],
    ): AccessResult {
        $result = AccessResult::neutral('No policy provided an opinion.');
        $entityTypeId = $entity->getEntityTypeId();
        $bundle = $entity->bundle();

        foreach ($this->policies as $index => $policy) {
            if (!$policy->appliesTo($entityTypeId)) {
                continue;
            }
            if (!$this->matchesBundle($this->bundleFilters[$index] ?? [], $bundle)) {
                continue;
            }

            $policyResult = $policy instanceof ContextAwareAccessPolicyInterface
                ? $policy->accessWithContext($entity, $operation, $account, $context)
                : $policy->access($entity, $operation, $account);
            $result = $result->orIf($policyResult);

            // Short-circuit on Forbidden — nothing can override it.
            if ($result->isForbidden()) {
                return $result;
            }
        }

        // 'translate' fallthrough: if no policy opined (aggregate Neutral) and the
        // operation is 'translate', re-check 'update'. translate ⊆ update by default.
        // Explicit Forbidden on translate is already short-circuited above and never
        // reaches this branch, so it is honored over the update fallback.
        if ($operation === self::OPERATION_TRANSLATE && $result->isNeutral()) {
            return $this->check($entity, GateInterface::UPDATE, $account, $context);
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

        foreach ($this->policies as $index => $policy) {
            if (!$policy->appliesTo($entityTypeId)) {
                continue;
            }
            if (!$this->matchesBundle($this->bundleFilters[$index] ?? [], $bundle)) {
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
        $bundle = $entity->bundle();

        foreach ($this->policies as $index => $policy) {
            if (!$policy->appliesTo($entityTypeId)) {
                continue;
            }
            if (!$policy instanceof FieldAccessPolicyInterface) {
                continue;
            }
            if (!$this->matchesBundle($this->bundleFilters[$index] ?? [], $bundle)) {
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

    /**
     * Resolve the entity's LABEL/TITLE the way it is safe to emit to the
     * viewing account: {@see EntityInterface::label()} when the entity type's
     * `label` entity-key field is not Forbidden for 'view', `null` when it is.
     *
     * This closes the label/title channel that entity-level view access and
     * the fields-bag filter (see {@see filterFields()}) both miss: SSR's
     * `<title>`, schema.org JSON-LD `name`, and the Markdown H1 all read
     * {@see EntityInterface::label()} directly rather than going through the
     * fields bag, so a policy forbidding the label-key field on 'view' would
     * otherwise still leak it on an entity that is viewable at the entity
     * level (R7 WP1; see CHANGELOG "Security").
     *
     * Open-by-default, matching field-access semantics elsewhere: a label
     * field with no opinionated policy (Neutral) is shown. Entity types with
     * no `label` entity key have nothing to gate — the label is returned
     * unchanged.
     *
     * Callers MUST fail closed on a `null` return (render a non-identifying
     * placeholder, e.g. the entity type id — never fall back to the raw
     * label).
     */
    public function viewableLabel(
        EntityInterface $entity,
        AccountInterface $account,
        EntityTypeManagerInterface $entityTypeManager,
    ): ?string {
        $label = $entity->label();
        $entityTypeId = $entity->getEntityTypeId();

        if (!$entityTypeManager->hasDefinition($entityTypeId)) {
            return $label;
        }

        $labelFieldName = $entityTypeManager->getDefinition($entityTypeId)->getKeys()['label'] ?? null;
        if ($labelFieldName === null || $labelFieldName === '') {
            return $label;
        }

        return $this->checkFieldAccess($entity, $labelFieldName, 'view', $account)->isForbidden() ? null : $label;
    }

    /**
     * @return string[] Bundles the policy opts into, or [] to apply to all bundles.
     */
    private function resolveBundles(AccessPolicyInterface $policy): array
    {
        $attributes = new \ReflectionClass($policy)->getAttributes(AccessPolicy::class);
        if ($attributes === []) {
            return [];
        }

        /** @var AccessPolicy $attribute */
        $attribute = $attributes[0]->newInstance();

        return $attribute->bundles;
    }

    /**
     * @param string[] $bundles
     */
    private function matchesBundle(array $bundles, string $bundle): bool
    {
        return $bundles === [] || in_array($bundle, $bundles, true);
    }
}
