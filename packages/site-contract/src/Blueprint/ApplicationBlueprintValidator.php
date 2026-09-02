<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

use Waaseyaa\SiteContract\ManifestShapeReader;

/**
 * Semantic (cross-collection) validation for a structurally-typed
 * {@see ApplicationBlueprint} (#2785, design §5, §7).
 *
 * `ApplicationBlueprintParser` types shape, grammar, and per-collection
 * identity uniqueness only; it never resolves a reference into another
 * collection. This class is where every cross-reference, prerequisite, and
 * business rule that needs the *whole* blueprint (and the manifest's
 * `content_types` ids) is enforced — invoked by `SiteManifestParser::parse()`
 * immediately after structural parsing succeeds.
 *
 * Not `@api`: invoked only by `SiteManifestParser`, not a public extension
 * point.
 */
final class ApplicationBlueprintValidator
{
    use ManifestShapeReader;

    private const string ROOT = '/application_blueprint';

    /** @param list<string> $contentTypeIds */
    public function validate(ApplicationBlueprint $blueprint, array $contentTypeIds, string $source): void
    {
        $this->checkContractVersion($blueprint, $source);
        $this->checkEntitiesAreContentTypes($blueprint, $contentTypeIds, $source);
        $this->checkFieldNamespaceCollisions($blueprint, $source);
        $this->checkEntityKeys($blueprint, $source);
        $this->checkFieldPrerequisites($blueprint, $source);
        $this->checkRelationships($blueprint, $source);
        $bindings = $this->checkWorkflows($blueprint, $source);
        $this->checkRoles($blueprint, $source);
        $this->checkPolicies($blueprint, $bindings, $source);
        $this->checkFixtures($blueprint, $source);
        $this->checkChecks($blueprint, $source);
    }

    private function checkContractVersion(ApplicationBlueprint $blueprint, string $source): void
    {
        if ($blueprint->contractVersion !== ApplicationBlueprint::CONTRACT_VERSION) {
            $this->fail($source, 'SITE040_BLUEPRINT_UNSUPPORTED_CONTRACT_VERSION', self::ROOT . '/contract_version', 'Unsupported application blueprint contract version.');
        }
    }

    /** @param list<string> $contentTypeIds */
    private function checkEntitiesAreContentTypes(ApplicationBlueprint $blueprint, array $contentTypeIds, string $source): void
    {
        $known = array_flip($contentTypeIds);
        $index = 0;
        foreach ($blueprint->entities as $entity) {
            if (!isset($known[$entity->id])) {
                $this->fail($source, 'SITE041_BLUEPRINT_UNKNOWN_CONTENT_TYPE', self::ROOT . "/entities/{$index}/id", 'A blueprint entity must name an existing content type.');
            }
            ++$index;
        }
    }

    private function checkFieldNamespaceCollisions(ApplicationBlueprint $blueprint, string $source): void
    {
        $entityIndex = 0;
        foreach ($blueprint->entities as $entity) {
            $reserved = array_filter([
                $entity->keys->id,
                $entity->keys->uuid,
                $entity->keys->revision,
                $entity->keys->langcode,
                $entity->keys->defaultLangcode,
                $entity->keys->owner,
            ], static fn(?string $value): bool => $value !== null);
            $fieldIndex = 0;
            foreach ($entity->fields as $field) {
                if (in_array($field->id, $reserved, true)) {
                    $this->fail($source, 'SITE020_DUPLICATE_ID', self::ROOT . "/entities/{$entityIndex}/fields/{$fieldIndex}/id", 'A field id must not collide with an entity key.');
                }
                ++$fieldIndex;
            }
            ++$entityIndex;
        }

        $relationshipIndex = 0;
        foreach ($blueprint->relationships as $relationship) {
            $entity = $blueprint->entities[$relationship->fromEntity] ?? null;
            if ($entity !== null && array_key_exists($relationship->fromField, $entity->fields)) {
                $this->fail($source, 'SITE020_DUPLICATE_ID', self::ROOT . "/relationships/{$relationshipIndex}/from/field", 'A relationship field id must not collide with a declared entity field.');
            }
            ++$relationshipIndex;
        }
    }

    private function checkEntityKeys(ApplicationBlueprint $blueprint, string $source): void
    {
        $index = 0;
        foreach ($blueprint->entities as $entity) {
            $path = self::ROOT . "/entities/{$index}";
            if (!array_key_exists($entity->keys->label, $entity->fields)) {
                $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/keys/label', 'The label key must name a declared field.');
            }
            if ($entity->keys->owner !== null && !$this->ownerFieldResolves($blueprint, $entity->id, $entity->keys->owner)) {
                $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/keys/owner', 'The owner key must name a relationship field on this entity.');
            }

            if ($entity->revisionable && $entity->keys->revision === null) {
                $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/keys/revision', 'A revisionable entity must declare a revision key.');
            }
            if (!$entity->revisionable && $entity->keys->revision !== null) {
                $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/keys/revision', 'Only a revisionable entity may declare a revision key.');
            }
            if ($entity->translatable && $entity->keys->langcode === null) {
                $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/keys/langcode', 'A translatable entity must declare a langcode key.');
            }
            if (!$entity->translatable && $entity->keys->langcode !== null) {
                $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/keys/langcode', 'Only a translatable entity may declare a langcode key.');
            }
            if ($entity->translatable && $entity->keys->defaultLangcode === null) {
                $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/keys/default_langcode', 'A translatable entity must declare a default_langcode key.');
            }
            if (!$entity->translatable && $entity->keys->defaultLangcode !== null) {
                $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/keys/default_langcode', 'Only a translatable entity may declare a default_langcode key.');
            }
            ++$index;
        }
    }

    private function ownerFieldResolves(ApplicationBlueprint $blueprint, string $entityId, string $ownerField): bool
    {
        foreach ($blueprint->relationships as $relationship) {
            if ($relationship->fromEntity === $entityId && $relationship->fromField === $ownerField) {
                return true;
            }
        }

        return false;
    }

    private function checkFieldPrerequisites(ApplicationBlueprint $blueprint, string $source): void
    {
        $entityIndex = 0;
        foreach ($blueprint->entities as $entity) {
            $fieldIndex = 0;
            foreach ($entity->fields as $field) {
                $path = self::ROOT . "/entities/{$entityIndex}/fields/{$fieldIndex}";
                if ($field->translatable && !$entity->translatable) {
                    $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/translatable', 'A translatable field requires a translatable entity.');
                }
                if ($field->revisionable && !$entity->revisionable) {
                    $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/revisionable', 'A revisionable field requires a revisionable entity.');
                }
                if ($field->indexed && $entity->storage !== BlueprintStorage::SqlColumn) {
                    $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/indexed', 'An indexed field requires sql-column storage.');
                }
                if ($field->type === BlueprintFieldType::Enum && $field->values === null) {
                    $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/values', 'An enum field must declare values.');
                }
                if ($field->type !== BlueprintFieldType::Enum && $field->values !== null) {
                    $this->fail($source, 'SITE044_BLUEPRINT_FIELD_PREREQUISITE', $path . '/values', 'Only an enum field may declare values.');
                }
                ++$fieldIndex;
            }
            ++$entityIndex;
        }
    }

    private function checkRelationships(ApplicationBlueprint $blueprint, string $source): void
    {
        $index = 0;
        foreach ($blueprint->relationships as $relationship) {
            $path = self::ROOT . "/relationships/{$index}";
            if (!array_key_exists($relationship->fromEntity, $blueprint->entities)) {
                $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/from/entity', 'A relationship must reference a blueprint entity.');
            }
            if (!array_key_exists($relationship->toEntity, $blueprint->entities)) {
                $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/to/entity', 'A relationship must reference a blueprint entity.');
            }
            ++$index;
        }
    }

    /** @return array<string, string> entity id => workflow id */
    private function checkWorkflows(ApplicationBlueprint $blueprint, string $source): array
    {
        $bindings = [];
        $index = 0;
        foreach ($blueprint->workflows as $workflow) {
            $path = self::ROOT . "/workflows/{$index}";
            if (!array_key_exists($workflow->initialState, $workflow->states)) {
                $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/initial_state', 'The initial state must be a declared workflow state.');
            }

            $transitionIndex = 0;
            foreach ($workflow->transitions as $transition) {
                $transitionPath = $path . "/transitions/{$transitionIndex}";
                $fromIndex = 0;
                foreach ($transition->from as $fromState) {
                    if (!array_key_exists($fromState, $workflow->states)) {
                        $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $transitionPath . "/from/{$fromIndex}", 'A transition source state must be a declared workflow state.');
                    }
                    ++$fromIndex;
                }
                if (!array_key_exists($transition->to, $workflow->states)) {
                    $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $transitionPath . '/to', 'A transition target state must be a declared workflow state.');
                }
                if (!array_key_exists($transition->permission, $blueprint->permissions)) {
                    $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $transitionPath . '/permission', 'A transition permission must be declared.');
                }
                ++$transitionIndex;
            }

            $bindingIndex = 0;
            foreach ($workflow->bindings as $binding) {
                $bindingPath = $path . "/bindings/{$bindingIndex}";
                $entity = $blueprint->entities[$binding->entity] ?? null;
                if ($entity === null) {
                    $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $bindingPath . '/entity', 'A workflow binding must reference a blueprint entity.');
                }
                if (!$entity->revisionable || $entity->translatable) {
                    $this->fail($source, 'SITE043_BLUEPRINT_WORKFLOW_BINDING_UNSUPPORTED', $bindingPath . '/entity', 'A bound entity must be revisionable and not translatable.');
                }
                if (isset($bindings[$binding->entity])) {
                    $this->fail($source, 'SITE043_BLUEPRINT_WORKFLOW_BINDING_UNSUPPORTED', $bindingPath . '/entity', 'An entity may be bound to at most one workflow.');
                }
                $bindings[$binding->entity] = $workflow->id;
                ++$bindingIndex;
            }
            ++$index;
        }

        return $bindings;
    }

    private function checkRoles(ApplicationBlueprint $blueprint, string $source): void
    {
        $index = 0;
        foreach ($blueprint->roles as $role) {
            $permissionIndex = 0;
            foreach ($role->permissions as $permission) {
                if (!array_key_exists($permission, $blueprint->permissions)) {
                    $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', self::ROOT . "/roles/{$index}/permissions/{$permissionIndex}", 'A role permission must be declared.');
                }
                ++$permissionIndex;
            }
            ++$index;
        }
    }

    /** @param array<string, string> $bindings entity id => workflow id */
    private function checkPolicies(ApplicationBlueprint $blueprint, array $bindings, string $source): void
    {
        $index = 0;
        foreach ($blueprint->policies as $policy) {
            $path = self::ROOT . "/policies/{$index}";
            if (!array_key_exists($policy->entity, $blueprint->entities)) {
                $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/entity', 'A policy must reference a blueprint entity.');
            }
            $condition = $policy->condition;
            if ($condition->permission !== null && !array_key_exists($condition->permission, $blueprint->permissions)) {
                $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/condition/permission', 'A policy condition permission must be declared.');
            }
            if ($condition->kind === BlueprintConditionKind::Ownership) {
                $entity = $blueprint->entities[$policy->entity];
                if ($entity->keys->owner === null) {
                    $this->fail($source, 'SITE046_BLUEPRINT_OWNERSHIP_FIELD_REQUIRED', $path . '/condition', 'An ownership condition requires an entity owner key.');
                }
            }
            if ($condition->kind === BlueprintConditionKind::WorkflowState) {
                $workflowId = $bindings[$policy->entity] ?? null;
                if ($workflowId === null) {
                    $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/condition', 'A workflow_state condition requires the entity to be bound to exactly one workflow.');
                }
                $workflow = $blueprint->workflows[$workflowId];
                $stateIndex = 0;
                foreach ($condition->states ?? [] as $state) {
                    if (!array_key_exists($state, $workflow->states)) {
                        $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . "/condition/states/{$stateIndex}", 'A workflow_state condition state must exist in the bound workflow.');
                    }
                    ++$stateIndex;
                }
            }
            ++$index;
        }
    }

    private function checkFixtures(ApplicationBlueprint $blueprint, string $source): void
    {
        $index = 0;
        foreach ($blueprint->fixtures as $fixture) {
            $path = self::ROOT . "/fixtures/{$index}";
            $entity = $blueprint->entities[$fixture->entity] ?? null;
            if ($entity === null) {
                $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/entity', 'A fixture must reference a blueprint entity.');
            }

            $relationshipsByField = [];
            foreach ($blueprint->relationships as $relationship) {
                if ($relationship->fromEntity === $fixture->entity) {
                    $relationshipsByField[$relationship->fromField] = $relationship;
                }
            }

            foreach ($fixture->values as $fieldId => $fieldValue) {
                $valuePath = $path . '/values/' . $this->pointer($fieldId);
                $relationship = $relationshipsByField[$fieldId] ?? null;
                if ($relationship === null && !array_key_exists($fieldId, $entity->fields)) {
                    $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $valuePath, 'A fixture value must name a declared field or relationship.');
                }
                if ($relationship !== null) {
                    $targets = is_array($fieldValue) ? $fieldValue : [$fieldValue];
                    foreach ($targets as $target) {
                        $targetFixture = is_string($target) ? ($blueprint->fixtures[$target] ?? null) : null;
                        if ($targetFixture === null || $targetFixture->entity !== $relationship->toEntity) {
                            $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $valuePath, 'A relationship fixture value must reference a fixture of the target entity.');
                        }
                    }
                }
            }

            if ($fixture->workflowState !== null) {
                $workflowId = null;
                foreach ($blueprint->workflows as $workflow) {
                    foreach ($workflow->bindings as $binding) {
                        if ($binding->entity === $fixture->entity) {
                            $workflowId = $workflow->id;
                        }
                    }
                }
                $workflow = $workflowId !== null ? $blueprint->workflows[$workflowId] : null;
                if ($workflow === null || !array_key_exists($fixture->workflowState, $workflow->states)) {
                    $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/workflow_state', 'A fixture workflow_state requires the entity to be bound to a workflow declaring that state.');
                }
            }
            ++$index;
        }
    }

    private function checkChecks(ApplicationBlueprint $blueprint, string $source): void
    {
        $index = 0;
        foreach ($blueprint->checks as $check) {
            $path = self::ROOT . "/checks/{$index}";
            match ($check->kind) {
                BlueprintCheckKind::RolePermission => $this->checkRolePermissionCheck($blueprint, $check, $path, $source),
                BlueprintCheckKind::WorkflowTransition => $this->checkWorkflowTransitionCheck($blueprint, $check, $path, $source),
                BlueprintCheckKind::EntityAccess => $this->checkEntityAccessCheck($blueprint, $check, $path, $source),
                BlueprintCheckKind::FixturePresent => $this->checkFixturePresentCheck($blueprint, $check, $path, $source),
            };
            ++$index;
        }
    }

    private function checkRolePermissionCheck(ApplicationBlueprint $blueprint, BlueprintCheck $check, string $path, string $source): void
    {
        if (!array_key_exists($check->role, $blueprint->roles)) {
            $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/role', 'A check role must be declared.');
        }
        if (!array_key_exists($check->permission, $blueprint->permissions)) {
            $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/permission', 'A check permission must be declared.');
        }
    }

    private function checkWorkflowTransitionCheck(ApplicationBlueprint $blueprint, BlueprintCheck $check, string $path, string $source): void
    {
        if (!array_key_exists($check->role, $blueprint->roles)) {
            $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/role', 'A check role must be declared.');
        }
        $workflow = $blueprint->workflows[$check->workflow] ?? null;
        if ($workflow === null) {
            $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/workflow', 'A check workflow must be declared.');
        }
        if (!array_key_exists($check->transition, $workflow->transitions)) {
            $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/transition', 'A check transition must exist in the referenced workflow.');
        }
    }

    private function checkEntityAccessCheck(ApplicationBlueprint $blueprint, BlueprintCheck $check, string $path, string $source): void
    {
        if (!array_key_exists($check->role, $blueprint->roles)) {
            $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/role', 'A check role must be declared.');
        }
        $entity = $blueprint->entities[$check->entity] ?? null;
        if ($entity === null) {
            $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/entity', 'A check must reference a blueprint entity.');
        }
        if ($check->fixture !== null) {
            $fixture = $blueprint->fixtures[$check->fixture] ?? null;
            if ($fixture === null || $fixture->entity !== $check->entity) {
                $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/fixture', 'A check fixture must exist and belong to the checked entity.');
            }
        }
    }

    private function checkFixturePresentCheck(ApplicationBlueprint $blueprint, BlueprintCheck $check, string $path, string $source): void
    {
        if (!array_key_exists($check->fixture, $blueprint->fixtures)) {
            $this->fail($source, 'SITE042_BLUEPRINT_UNRESOLVED_REFERENCE', $path . '/fixture', 'A check fixture must be declared.');
        }
    }
}
