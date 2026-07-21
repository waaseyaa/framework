<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Access\Attribute\AccessPolicy;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;
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
    /** @var \Closure(EntityBase, list<string>): PolicySubjectViewInterface */
    private readonly \Closure $entityPolicySubjectAuthority;

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
        $authority = \Closure::bind(
            static fn(EntityBase $entity, array $fields = []): PolicySubjectViewInterface => $entity->valueContainer->entityPolicySubjectView($fields),
            null,
            EntityBase::class,
        );
        $this->entityPolicySubjectAuthority = $authority;
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
     * @param AuthorizationPrincipalInterface $account The immutable principal requesting access.
     * @param array<string, mixed> $context Optional extra context. For 'translate':
     *                                       ['langcode' => string].
     */
    public function check(
        EntityInterface $entity,
        string $operation,
        AccountInterface $account,
        array $context = [],
    ): AccessResult {
        $this->assertImmutableDecisionAccount($account);

        if ($operation === GateInterface::VIEW
            && $entity instanceof EntityBase
            && $this->isAuthorizationPrincipal($account)
            && $this->hasProtectedEntityReadPolicy($entity->getEntityTypeId(), $entity->bundle())
        ) {
            $legacySubject = ($this->entityPolicySubjectAuthority)($entity, []);
            $subject = ($this->entityPolicySubjectAuthority)($entity, $this->protectedEntityReadInputs($entity->getEntityTypeId(), $entity->bundle()));

            return $this->checkProtectedEntityRead($account, $entity->entityStructure(), $subject, $operation, $legacySubject);
        }

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

    /** Fail-closed Protected entity decision over immutable principal and structural subject data. */
    public function checkProtectedEntityRead(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation = GateInterface::VIEW,
        ?PolicySubjectViewInterface $legacySubject = null,
    ): AccessResult {
        $result = AccessResult::neutral('No protected entity-read policy provided an opinion.');
        foreach ($this->policies as $index => $policy) {
            if (!$policy->appliesTo($structure->entityTypeId)
                || !$this->matchesBundle($this->bundleFilters[$index] ?? [], $structure->bundleId)
                || !$policy instanceof ProtectedReadPolicyProviderInterface
            ) {
                continue;
            }
            $entityPolicy = $policy->protectedEntityReadPolicy();
            if ($entityPolicy === null) {
                continue;
            }
            if ($entityPolicy instanceof ClassifiedProtectedEntityReadPolicyInterface && $operation !== GateInterface::VIEW) {
                continue;
            }
            $policySubject = $legacySubject ?? $subject;
            if ($entityPolicy instanceof ClassifiedProtectedEntityReadPolicyInterface || $entityPolicy instanceof ProjectedProtectedEntityReadPolicyInterface) {
                $policySubject = $subject;
                $declaredInputs = $this->declaredProtectedEntityReadInputs($entityPolicy);
                $values = [];
                foreach ($declaredInputs as $field) {
                    if (!in_array($field, $policySubject->fields(), true)) {
                        return AccessResult::forbidden(sprintf('Protected entity-read subject is missing required field "%s".', $field));
                    }
                    $values[$field] = $policySubject->get($field);
                }
                $policySubject = new CompiledPolicySubjectView($values);
            }
            $result = $result->orIf($entityPolicy->access($principal, $structure, $policySubject, $operation));
            if ($result->isForbidden()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Resolve every V2 Protected entity-read policy for one exact type/bundle.
     *
     * A non-null result is complete for the same policy set used by
     * checkProtectedEntityRead(); callers may therefore evaluate an immutable
     * compiled subject without constructing an Entity object.
     *
     * @internal
     */
    public function protectedEntityReadProjectionPlan(string $entityTypeId, string $bundle): ?ProtectedEntityReadPlan
    {
        $policies = [];
        $inputs = [];
        $foundProtectedPolicy = false;
        foreach ($this->policies as $index => $policy) {
            if (!$policy->appliesTo($entityTypeId)
                || !$this->matchesBundle($this->bundleFilters[$index] ?? [], $bundle)
                || !$policy instanceof ProtectedReadPolicyProviderInterface
            ) {
                continue;
            }
            $entityPolicy = $policy->protectedEntityReadPolicy();
            if ($entityPolicy === null) {
                continue;
            }
            $foundProtectedPolicy = true;
            if (!$entityPolicy instanceof ClassifiedProtectedEntityReadPolicyInterface
                && !$entityPolicy instanceof ProjectedProtectedEntityReadPolicyInterface
            ) {
                return null;
            }
            $inputsForPolicy = $this->declaredProtectedEntityReadInputs($entityPolicy);
            $policies[] = ['policy' => $entityPolicy, 'inputs' => $inputsForPolicy];
            foreach ($inputsForPolicy as $fieldName) {
                $inputs[$fieldName] = true;
            }
        }

        if (!$foundProtectedPolicy) {
            return null;
        }

        $inputNames = array_keys($inputs);
        sort($inputNames);

        return new ProtectedEntityReadPlan($entityTypeId, $bundle, $inputNames, $policies);
    }

    /**
     * Resolve the complete Protected policy set when at least one policy needs
     * consistent-snapshot batch authorization.
     *
     * @internal
     */
    public function contextualProtectedEntityReadPlan(string $entityTypeId, string $bundle): ?ContextualProtectedEntityReadPlan
    {
        $policies = [];
        $inputs = [];
        $boundary = null;
        $found = false;
        $contextKeys = [];
        foreach ($this->policies as $index => $policy) {
            if (!$policy->appliesTo($entityTypeId)
                || !$this->matchesBundle($this->bundleFilters[$index] ?? [], $bundle)
                || !$policy instanceof ProtectedReadPolicyProviderInterface
            ) {
                continue;
            }
            $entityPolicy = $policy->protectedEntityReadPolicy();
            if ($entityPolicy === null) {
                continue;
            }
            if ($entityPolicy instanceof ContextualProtectedEntityReadPolicyInterface) {
                $found = true;
                $policyBoundary = $entityPolicy->authorizationBoundary();
                if ($boundary !== null && $boundary !== $policyBoundary) {
                    throw new \LogicException('Contextual entity-read policies must share one authorization boundary.');
                }
                $boundary = $policyBoundary;
                $contextKey = $entityPolicy->contextKey();
                if ($contextKey === '' || isset($contextKeys[$contextKey])) {
                    throw new \LogicException('Contextual entity-read policy keys must be non-empty and unique.');
                }
                $contextKeys[$contextKey] = true;
            }
            if ($entityPolicy instanceof ContextualProtectedEntityReadPolicyInterface) {
                $policyInputs = [];
            } elseif ($entityPolicy instanceof ClassifiedProtectedEntityReadPolicyInterface
                || $entityPolicy instanceof ProjectedProtectedEntityReadPolicyInterface
            ) {
                $policyInputs = $this->declaredProtectedEntityReadInputs($entityPolicy);
            } else {
                $policyInputs = null;
            }
            $policies[] = ['policy' => $entityPolicy, 'inputs' => $policyInputs];
            if ($policyInputs !== null) {
                foreach ($policyInputs as $input) {
                    $inputs[$input] = true;
                }
            }
        }

        if (!$found || $boundary === null) {
            return null;
        }
        foreach ($policies as $entry) {
            if ($entry['inputs'] === null) {
                throw new \LogicException('Contextual entity reads cannot compose an undeclared hydrated policy input shape.');
            }
        }
        $inputNames = array_keys($inputs);
        sort($inputNames);

        return new ContextualProtectedEntityReadPlan(
            $entityTypeId,
            $bundle,
            $boundary,
            $inputNames,
            array_keys($contextKeys),
            $policies,
        );
    }

    /** Build the exact immutable subject shape declared by a contextual plan. @internal */
    public function contextualProtectedReadCandidate(
        EntityBase $entity,
        ContextualProtectedEntityReadPlan $plan,
    ): ContextualProtectedReadCandidate {
        $structure = $entity->entityStructure();
        if ($structure->entityTypeId !== $plan->entityTypeId || $structure->bundleId !== $plan->bundle) {
            throw new \LogicException('Contextual read candidate does not match its policy plan.');
        }
        $subject = ($this->entityPolicySubjectAuthority)($entity, $plan->authorizationInputs);
        $values = [];
        foreach ($plan->authorizationInputs as $field) {
            if (!in_array($field, $subject->fields(), true)) {
                throw new \LogicException(sprintf('Contextual read candidate is missing required field "%s".', $field));
            }
            $values[$field] = $subject->get($field);
        }

        return new ContextualProtectedReadCandidate(
            (string) $structure->id,
            $structure,
            new CompiledPolicySubjectView($values),
        );
    }

    /** @internal Query ordering and cache safety for application classification policies. */
    public function hasClassifiedProtectedEntityReadPolicy(string $entityTypeId, ?string $bundle): bool
    {
        foreach ($this->policies as $index => $policy) {
            if (!$policy->appliesTo($entityTypeId)
                || ($bundle !== null && !$this->matchesBundle($this->bundleFilters[$index] ?? [], $bundle))
                || !$policy instanceof ProtectedReadPolicyProviderInterface
            ) {
                continue;
            }
            if ($policy->protectedEntityReadPolicy() instanceof ClassifiedProtectedEntityReadPolicyInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check access for creating a new entity.
     *
     * @param string           $entityTypeId The entity type ID.
     * @param string           $bundle       The bundle.
     * @param AuthorizationPrincipalInterface $account The immutable principal requesting access.
     */
    public function checkCreateAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        $this->assertImmutableDecisionAccount($account);

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
     * @param AuthorizationPrincipalInterface $account The immutable principal requesting access.
     */
    public function checkFieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        $this->assertImmutableDecisionAccount($account);

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
     * Fail-closed Protected field decision over immutable principal and structural subject data.
     */
    public function checkProtectedFieldRead(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        $result = AccessResult::neutral('No protected field-read policy provided an opinion.');
        foreach ($this->policies as $index => $policy) {
            if (!$policy->appliesTo($structure->entityTypeId)
                || !$this->matchesBundle($this->bundleFilters[$index] ?? [], $structure->bundleId)
                || !$policy instanceof ProtectedReadPolicyProviderInterface
            ) {
                continue;
            }
            $fieldPolicy = $policy->protectedFieldReadPolicy();
            if ($fieldPolicy === null) {
                continue;
            }
            $result = $result->orIf($fieldPolicy->access($principal, $structure, $subject, $fieldName));
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
     * @param AuthorizationPrincipalInterface $account The immutable principal requesting access.
     *
     * @return string[] Field names that are not forbidden.
     */
    public function filterFields(
        EntityInterface $entity,
        array $fieldNames,
        string $operation,
        AccountInterface $account,
    ): array {
        $this->assertImmutableDecisionAccount($account);

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
     *
     * @param AuthorizationPrincipalInterface $account The immutable principal requesting access.
     */
    public function viewableLabel(
        EntityInterface $entity,
        AccountInterface $account,
        EntityTypeManagerInterface $entityTypeManager,
    ): ?string {
        $this->assertImmutableDecisionAccount($account);

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

    private function isAuthorizationPrincipal(AccountInterface $account): bool
    {
        return $account instanceof AuthorizationPrincipalInterface;
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

    private function hasProtectedEntityReadPolicy(string $entityTypeId, string $bundle): bool
    {
        foreach ($this->policies as $index => $policy) {
            if ($policy->appliesTo($entityTypeId)
                && $this->matchesBundle($this->bundleFilters[$index] ?? [], $bundle)
                && $policy instanceof ProtectedReadPolicyProviderInterface
                && $policy->protectedEntityReadPolicy() !== null
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function protectedEntityReadInputs(string $entityTypeId, string $bundle): array
    {
        $inputs = [];
        foreach ($this->policies as $index => $policy) {
            if (!$policy->appliesTo($entityTypeId)
                || !$this->matchesBundle($this->bundleFilters[$index] ?? [], $bundle)
                || !$policy instanceof ProtectedReadPolicyProviderInterface
            ) {
                continue;
            }
            $entityPolicy = $policy->protectedEntityReadPolicy();
            if ($entityPolicy instanceof ClassifiedProtectedEntityReadPolicyInterface || $entityPolicy instanceof ProjectedProtectedEntityReadPolicyInterface) {
                $declaredInputs = $this->declaredProtectedEntityReadInputs($entityPolicy);
                foreach ($declaredInputs as $field) {
                    $inputs[$field] = true;
                }
            }
        }
        $fields = array_keys($inputs);
        sort($fields);
        return $fields;
    }

    /** @return list<string> */
    private function declaredProtectedEntityReadInputs(
        ClassifiedProtectedEntityReadPolicyInterface|ProjectedProtectedEntityReadPolicyInterface $policy,
    ): array {
        $inputs = $policy instanceof ClassifiedProtectedEntityReadPolicyInterface
            ? $policy->classificationInputs()
            : $policy->authorizationInputs();

        return $this->normalizeDeclaredProtectedEntityReadInputs($inputs);
    }

    /** @return list<string> */
    private function normalizeDeclaredProtectedEntityReadInputs(mixed $inputs): array
    {
        if (!is_array($inputs) || !array_is_list($inputs)) {
            throw new \LogicException('Protected entity-read policy classification inputs must be a list.');
        }
        foreach ($inputs as $field) {
            if (!is_string($field) || $field === '') {
                throw new \LogicException('Protected entity-read policy classification inputs must be non-empty strings.');
            }
        }
        if (count(array_unique($inputs)) !== count($inputs)) {
            throw new \LogicException('Protected entity-read policy classification inputs must not contain duplicate fields.');
        }

        sort($inputs);

        return $inputs;
    }

    /**
     * Mutable entity-backed accounts must be snapshotted at the audited identity
     * boundary before any access policy can inspect their claims.
     */
    private function assertImmutableDecisionAccount(AccountInterface $account): void
    {
        if ($account instanceof EntityInterface) {
            throw new \LogicException(sprintf(
                'Access decisions require an immutable AuthorizationPrincipal; mutable %s was provided.',
                $account::class,
            ));
        }
    }

    /**
     * @param string[] $bundles
     */
    private function matchesBundle(array $bundles, string $bundle): bool
    {
        return $bundles === [] || in_array($bundle, $bundles, true);
    }
}
