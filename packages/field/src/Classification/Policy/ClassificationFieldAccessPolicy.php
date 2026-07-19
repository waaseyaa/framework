<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Field\Classification\ClassificationClearanceCheckerInterface;
use Waaseyaa\Field\Classification\ClassificationLabelRegistryInterface;
use Waaseyaa\Field\Classification\ClassificationSubjectReader;
use Waaseyaa\Field\Classification\Permissions;

/**
 * Cross-cutting access policy that enforces classification semantics on every
 * entity type carrying a `classification_label` field.
 *
 * Two rules, evaluated in this order:
 *
 *   1. **Hold override (C-004 / FR-013).** When the entity's effective label
 *      begins with `hold-` and the account lacks the
 *      {@see Permissions::LEGAL_HOLD_BYPASS} permission, return Forbidden.
 *      Hold takes precedence over clearance: the clearance check is NOT
 *      performed when hold has decided.
 *   2. **Clearance gate (FR-005 / FR-008).** When the account's clearance
 *      level is lower than the label's
 *      {@see \Waaseyaa\Field\Entity\ClassificationLabelDefinition::getConfidentialityLevel()},
 *      return Forbidden. Otherwise return Neutral.
 *
 * For entity-level checks ({@see AccessPolicyInterface::access()}) the
 * convention is deny-by-default: this policy only ever returns Forbidden or
 * Neutral and leaves grant decisions to other policies. For field-level
 * checks ({@see FieldAccessPolicyInterface::fieldAccess()}) the convention
 * is open-by-default per `docs/specs/field-access.md`: same Forbidden/Neutral
 * semantics, no grant.
 *
 * The wildcard `entityType: '*'` registration is honoured by the framework's
 * `KernelPolicyDependencyResolver` (Rule 1: an `array` constructor parameter
 * receives the manifest-declared entity types verbatim), and is matched at
 * runtime by {@see appliesTo()} below.
 *
 * @api
 */
#[PolicyAttribute(entityType: '*')]
final class ClassificationFieldAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    /** @var list<string> */
    private readonly array $entityTypes;

    private readonly ClassificationSubjectReader $subjectReader;

    /**
     * @param ClassificationLabelRegistryInterface     $labels
     * @param ClassificationClearanceCheckerInterface  $clearance
     * @param array<int, mixed>                        $entityTypes Injected verbatim from the policy manifest by
     *                                                              {@see \Waaseyaa\Foundation\Kernel\Bootstrap\KernelPolicyDependencyResolver}
     *                                                              (Rule 1: an `array` constructor parameter receives
     *                                                              the manifest entity-type list). `['*']` for wildcard.
     *                                                              Defensively narrowed to strings here so a malformed
     *                                                              manifest can never poison `appliesTo()`.
     */
    public function __construct(
        private readonly ClassificationLabelRegistryInterface $labels,
        private readonly ClassificationClearanceCheckerInterface $clearance,
        array $entityTypes = ['*'],
        ?ClassificationSubjectReader $subjectReader = null,
    ) {
        $this->subjectReader = $subjectReader ?? new ClassificationSubjectReader();
        $normalized = [];
        foreach ($entityTypes as $type) {
            if (!is_string($type) || $type === '') {
                continue;
            }
            $normalized[] = $type;
        }
        $this->entityTypes = $normalized === [] ? ['*'] : $normalized;
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return in_array('*', $this->entityTypes, true)
            || in_array($entityTypeId, $this->entityTypes, true);
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (!in_array($operation, ['view', 'update', 'delete'], true)) {
            return AccessResult::neutral("Classification policy has no opinion on '$operation'.");
        }

        return $this->evaluate($entity, $account);
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        // 'create' precedes label assignment — defer to other policies.
        return AccessResult::neutral('Classification policy is silent on create.');
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        return $this->evaluate($entity, $account);
    }

    /**
     * Shared hold-then-clearance evaluator used by both entity-level and
     * field-level checks.
     */
    private function evaluate(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        $labelId = $this->resolveLabelId($entity);
        if ($labelId === null) {
            return AccessResult::neutral('Entity carries no classification label.');
        }

        // Rule 1 — Hold override (C-004 / FR-013).
        if (str_starts_with($labelId, 'hold-')) {
            if (!$account->hasPermission(Permissions::LEGAL_HOLD_BYPASS)) {
                return AccessResult::forbidden(
                    "Label '$labelId' is on hold; account lacks '"
                    . Permissions::LEGAL_HOLD_BYPASS . "' permission.",
                );
            }

            return AccessResult::neutral(
                "Label '$labelId' is on hold but account has bypass permission.",
            );
        }

        // Rule 2 — Clearance gate (FR-005 / FR-008).
        $definition = $this->labels->definition($labelId);
        if ($definition === null) {
            // Unknown label → no opinion. A misconfigured policy must not
            // silently lock everyone out of an otherwise-legitimate entity.
            return AccessResult::neutral("Label '$labelId' is not defined in the registry.");
        }

        $required = $definition->getConfidentialityLevel();
        $actual = $this->clearance->clearanceLevelFor($account);

        if ($actual < $required) {
            return AccessResult::forbidden(
                "Label '$labelId' requires clearance >= $required; account has $actual.",
            );
        }

        return AccessResult::neutral("Account clearance ($actual) meets label '$labelId' requirement ($required).");
    }

    /**
     * Read the entity's effective classification label.
     *
     * The label is materialised onto the `classification_label` column by
     * the {@see \Waaseyaa\Field\Classification\EntityLifecycleSubscriber}
     * on PRE_SAVE, so a simple field read suffices at access-check time.
     * Returns null when the entity does not carry the field (entities that
     * have not been wired into the classification system).
     */
    private function resolveLabelId(EntityInterface $entity): ?string
    {
        return $this->subjectReader->read($entity)->label;
    }
}
