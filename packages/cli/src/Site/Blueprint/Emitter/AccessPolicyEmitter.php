<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint\Emitter;

use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintConditionKind;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntity;
use Waaseyaa\SiteContract\Blueprint\BlueprintOperation;
use Waaseyaa\SiteContract\Blueprint\BlueprintPolicy;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * Emits one `src/Access/<PascalCase(entity)>Policy.php` per blueprint entity
 * that declares at least one policy (#2788, FW-SITE-BLUEPRINT-01E). An
 * entity with zero declared policies gets no generated class at all — access
 * to it is denied by the framework's own open-by-default `AccessResult`
 * semantics (Neutral from every policy = denied, `EntityAccessHandler::
 * check()`).
 *
 * The generated class implements {@see \Waaseyaa\Access\AccessPolicyInterface}
 * and is deliberately open-by-default at the entity level: every branch
 * either grants `Allowed` or defers with `Neutral`. It never returns an
 * entity-level `Forbidden` (a blueprint policy has no declarative shape for
 * "deny outright") and never inspects
 * `AccountInterface::getRoles()` directly — `Waaseyaa\User\Role` membership
 * is already resolved into `AccountInterface::hasPermission()` before this
 * policy ever runs, exactly the shape every hand-written policy
 * (`NodeAccessPolicy`) already assumes.
 *
 * A blueprint `condition.kind`:
 *  - `permission`: `Allowed` iff `$account->hasPermission($condition->permission)`.
 *  - `ownership`: `Allowed` iff the account is authenticated, owns the
 *    entity (string-equal `AccountInterface::id()` to the raw owner
 *    relationship field, read via {@see \Waaseyaa\Access\Read\AuthorizationInputReader}
 *    — mirrors `NodeAccessPolicy::editAccess()`'s ownership check verbatim,
 *    including the anonymous-cannot-own-an-authorless-entity guard), AND
 *    (when `condition.permission` is declared) holds that permission too.
 *  - `workflow_state`: `Allowed` iff the entity's raw `workflow_state` (also
 *    read via `AuthorizationInputReader`, always present because
 *    `ApplicationBlueprintValidator::checkPolicies()` already refuses a
 *    `workflow_state` condition on an entity not bound to exactly one
 *    workflow — `EntityClassEmitter` guarantees the field exists on every
 *    bound entity) is one of `condition.states`, AND (when
 *    `condition.permission` is declared) the account holds that permission.
 *
 * Multiple policies governing the same (entity, operation) pair combine with
 * OR — the first satisfied condition, in policy-id order for determinism,
 * grants `Allowed`; if none are satisfied the result is `Neutral`.
 *
 * When the entity declares an authorization input — `keys.owner`, or the
 * generated `workflow_state` selector on a workflow-bound entity — the class
 * also implements {@see \Waaseyaa\Access\FieldAccessPolicyInterface}
 * (#2788 review gap 3, see {@see self::renderFieldAccess()}): the owner
 * field is edit-Forbidden on a persisted entity and `workflow_state` is
 * edit-Forbidden always, so an entity-level `update` grant can never
 * rewrite the inputs it was decided on. `JsonApiController` consults this
 * through `EntityAccessHandler::checkFieldAccess()` on every submitted
 * attribute of `store()` and `update()`.
 *
 * `createAccess()` only ever evaluates `permission`-kind conditions:
 * `ownership` and `workflow_state` conditions targeting `create` are refused
 * at compile time (`GEN007_UNSUPPORTED_DECLARATION`, before any artifact is
 * emitted) — a not-yet-persisted entity has no owner or workflow state to
 * read.
 *
 * @api
 */
final class AccessPolicyEmitter implements BlueprintArtifactEmitterInterface
{
    public function id(): string
    {
        return 'access-policy';
    }

    public function emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission
    {
        self::assertNoUnsupportedCreateCondition($blueprint);

        $byEntity = [];
        foreach ($blueprint->policies as $policy) {
            $byEntity[$policy->entity][] = $policy;
        }

        $boundEntityIds = [];
        foreach ($blueprint->workflows as $workflow) {
            foreach ($workflow->bindings as $binding) {
                $boundEntityIds[$binding->entity] = true;
            }
        }

        $artifacts = [];
        foreach ($blueprint->entities as $entity) {
            $policies = $byEntity[$entity->id] ?? [];
            if ($policies === []) {
                continue;
            }
            usort($policies, static fn(BlueprintPolicy $left, BlueprintPolicy $right): int => strcmp($left->id, $right->id));

            $className = self::pascalCase($entity->id) . 'Policy';
            $artifacts[] = new GeneratedArtifact(
                'src/Access/' . $className . '.php',
                $this->renderPolicy($entity, $className, $policies, isset($boundEntityIds[$entity->id])),
            );
        }
        usort($artifacts, static fn(GeneratedArtifact $left, GeneratedArtifact $right): int => strcmp($left->path, $right->path));

        return new BlueprintEmission($artifacts);
    }

    /** @param list<BlueprintPolicy> $policies sorted by id */
    private function renderPolicy(BlueprintEntity $entity, string $className, array $policies, bool $workflowBound): string
    {
        $entityId = $entity->id;
        $entityClass = self::pascalCase($entityId);
        $ownerField = $entity->keys->owner;

        $fieldAccess = $this->renderFieldAccess($ownerField, $workflowBound);
        $fieldAccessUse = $fieldAccess === '' ? '' : "use Waaseyaa\\Access\\FieldAccessPolicyInterface;\n";
        $fieldAccessImplements = $fieldAccess === '' ? '' : ', FieldAccessPolicyInterface';

        $byOperation = [];
        foreach ($policies as $policy) {
            $byOperation[$policy->operation->value][] = $policy;
        }

        $matchArms = '';
        foreach ([BlueprintOperation::View, BlueprintOperation::Update, BlueprintOperation::Delete] as $operation) {
            $forOperation = $byOperation[$operation->value] ?? [];
            if ($forOperation === []) {
                continue;
            }
            $method = 'check' . ucfirst($operation->value) . 'Access';
            $matchArms .= "            '{$operation->value}' => self::{$method}(\$entity, \$account),\n";
        }

        $methods = '';
        foreach ([BlueprintOperation::View, BlueprintOperation::Update, BlueprintOperation::Delete] as $operation) {
            $forOperation = $byOperation[$operation->value] ?? [];
            if ($forOperation === []) {
                continue;
            }
            $methods .= $this->renderOperationMethod($operation, $forOperation, $ownerField);
        }

        $createBody = $this->renderCreateAccess($byOperation[BlueprintOperation::Create->value] ?? []);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Access;

            use Waaseyaa\\Access\\AccessPolicyInterface;
            use Waaseyaa\\Access\\AccessResult;
            use Waaseyaa\\Access\\AccountInterface;
            {$fieldAccessUse}use Waaseyaa\\Access\\Gate\\PolicyAttribute;
            use Waaseyaa\\Access\\Read\\AuthorizationInputReader;
            use Waaseyaa\\Entity\\EntityInterface;

            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand. Open-by-default: every entity-level branch below either
             * grants AccessResult::allowed() or defers with AccessResult::neutral(); this
             * policy never returns an entity-level Forbidden and never inspects roles
             * directly. Field-level: the entity's authorization inputs are edit-sealed so an
             * entity-level grant cannot rewrite the inputs it was decided on.
             */
            #[PolicyAttribute(entityType: '{$entityId}')]
            final class {$className} implements AccessPolicyInterface{$fieldAccessImplements}
            {
                public function appliesTo(string \$entityTypeId): bool
                {
                    return \$entityTypeId === '{$entityId}';
                }

                public function access(EntityInterface \$entity, string \$operation, AccountInterface \$account): AccessResult
                {
                    assert(\$entity instanceof \\App\\Entity\\{$entityClass});

                    return match (\$operation) {
            {$matchArms}            default => AccessResult::neutral("No policy opinion on '{\$operation}'."),
                    };
                }

                public function createAccess(string \$entityTypeId, string \$bundle, AccountInterface \$account): AccessResult
                {
            {$createBody}    }
            {$fieldAccess}{$methods}}

            PHP;
    }

    /**
     * The canonical field-access seal for the entity's authorization inputs
     * (#2788 review gap 3), or `''` when the entity declares none.
     *
     * - `keys.owner`: edit-Forbidden once the entity is persisted
     *   (`!isNew()`) — reassigning ownership is the escalation an
     *   `ownership` grant must not permit; settable at create, the
     *   `NodeAccessPolicy` authorship precedent (`ADMIN_ONLY_EDIT_FIELDS`).
     *   `EntityInterface::isNew()` is the lifecycle authority both
     *   `JsonApiController::store()` (a `create()`d entity) and `update()` (a
     *   loaded entity) present to `checkFieldAccess()`.
     * - `workflow_state` (workflow-bound entities): edit-Forbidden always —
     *   an engine-owned selector `TransitionService` alone moves.
     * - `view` and every other field: Neutral (Protected inputs are concealed
     *   by the read layout; ordinary fields stay open-by-default).
     */
    private function renderFieldAccess(?string $ownerField, bool $workflowBound): string
    {
        if ($ownerField === null && !$workflowBound) {
            return '';
        }

        $ownerLiteral = $ownerField === null ? '' : self::singleQuoted($ownerField);
        $ownerClause = $ownerField === null ? '' : <<<PHP
                    if (\$fieldName === {$ownerLiteral} && !\$entity->isNew()) {
                        return AccessResult::forbidden('Ownership of a persisted entity is not reassignable through an update grant.');
                    }

            PHP;
        $workflowClause = !$workflowBound ? '' : <<<'PHP'
                    if ($fieldName === 'workflow_state') {
                        return AccessResult::forbidden('workflow_state is engine-owned; move it through a workflow transition.');
                    }

            PHP;

        return <<<PHP

                public function fieldAccess(EntityInterface \$entity, string \$fieldName, string \$operation, AccountInterface \$account): AccessResult
                {
                    if (\$operation !== 'edit') {
                        return AccessResult::neutral('Authorization inputs are sealed against edit only.');
                    }
            {$ownerClause}{$workflowClause}
                    return AccessResult::neutral("No field-edit opinion on '{\$fieldName}'.");
                }

            PHP;
    }

    /** @param list<BlueprintPolicy> $policies sorted by id, all sharing one non-Create operation */
    private function renderOperationMethod(BlueprintOperation $operation, array $policies, ?string $ownerField): string
    {
        $method = 'check' . ucfirst($operation->value) . 'Access';
        $needsInputs = self::anyNeedsAuthorizationInputs($policies);
        $inputsLine = $needsInputs ? "        \$inputs = new AuthorizationInputReader()->read(\$entity);\n\n" : '';

        $clauses = '';
        foreach ($policies as $policy) {
            $clauses .= $this->renderConditionClause($policy, $ownerField);
        }

        return <<<PHP

                private static function {$method}(EntityInterface \$entity, AccountInterface \$account): AccessResult
                {
            {$inputsLine}{$clauses}        return AccessResult::neutral('No policy condition granted access.');
                }

            PHP;
    }

    /** @param list<BlueprintPolicy> $policies */
    private static function anyNeedsAuthorizationInputs(array $policies): bool
    {
        foreach ($policies as $policy) {
            if ($policy->condition->kind !== BlueprintConditionKind::Permission) {
                return true;
            }
        }

        return false;
    }

    private function renderConditionClause(BlueprintPolicy $policy, ?string $ownerField): string
    {
        $condition = $policy->condition;
        $reason = self::singleQuoted("Policy '{$policy->id}' granted access.");

        return match ($condition->kind) {
            BlueprintConditionKind::Permission => <<<PHP
                        if (\$account->hasPermission({$this->quotedPermission($condition->permission)})) {
                            return AccessResult::allowed({$reason});
                        }

                PHP,
            BlueprintConditionKind::Ownership => <<<PHP
                        if (\$account->isAuthenticated()
                            && (\$inputs['{$ownerField}'] ?? null) !== null
                            && (string) \$account->id() === (string) \$inputs['{$ownerField}']{$this->andPermissionClause($condition->permission)}) {
                            return AccessResult::allowed({$reason});
                        }

                PHP,
            BlueprintConditionKind::WorkflowState => <<<PHP
                        if (\\in_array(\$inputs['workflow_state'] ?? null, {$this->statesArray($condition->states ?? [])}, true){$this->andPermissionClause($condition->permission)}) {
                            return AccessResult::allowed({$reason});
                        }

                PHP,
        };
    }

    private function andPermissionClause(?string $permission): string
    {
        if ($permission === null) {
            return '';
        }

        return "\n            && \$account->hasPermission({$this->quotedPermission($permission)})";
    }

    /** @param list<string> $states */
    private function statesArray(array $states): string
    {
        $sorted = $states;
        sort($sorted, SORT_STRING);
        $quoted = array_map(self::singleQuoted(...), $sorted);

        return '[' . implode(', ', $quoted) . ']';
    }

    /** @param list<BlueprintPolicy> $policies sorted by id, all sharing the Create operation (permission-kind only) */
    private function renderCreateAccess(array $policies): string
    {
        if ($policies === []) {
            return "        return AccessResult::neutral('No create policy declared for this entity.');\n";
        }

        $clauses = '';
        foreach ($policies as $policy) {
            $reason = self::singleQuoted("Policy '{$policy->id}' granted create access.");
            $clauses .= "        if (\$account->hasPermission({$this->quotedPermission($policy->condition->permission)})) {\n"
                . "            return AccessResult::allowed({$reason});\n"
                . "        }\n\n";
        }

        return $clauses . "        return AccessResult::neutral('No create policy condition granted access.');\n";
    }

    private function quotedPermission(?string $permission): string
    {
        \assert($permission !== null);

        return self::singleQuoted($permission);
    }

    private static function assertNoUnsupportedCreateCondition(ApplicationBlueprint $blueprint): void
    {
        $violations = [];
        $index = 0;
        foreach ($blueprint->policies as $policy) {
            if ($policy->operation === BlueprintOperation::Create
                && $policy->condition->kind !== BlueprintConditionKind::Permission) {
                $violations[] = new GenerationViolation(
                    GenerationErrorCode::UnsupportedDeclaration,
                    "Blueprint policy \"{$policy->id}\" declares a '{$policy->condition->kind->value}' condition on the 'create' operation; a not-yet-persisted entity has no owner or workflow state to evaluate. Only a 'permission' condition is supported on 'create'.",
                    pointer: "/application_blueprint/policies/{$index}/condition",
                );
            }
            ++$index;
        }

        if ($violations !== []) {
            throw new GenerationRefusalException(self::class, $violations);
        }
    }

    private static function singleQuoted(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private static function pascalCase(string $id): string
    {
        return str_replace('_', '', ucwords(str_replace('-', '_', $id), '_'));
    }
}
