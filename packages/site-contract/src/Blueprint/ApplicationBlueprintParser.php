<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\ManifestShapeReader;

/**
 * Structural typing for the optional `application_blueprint` section of a
 * `waaseyaa.site` v1 manifest (#2785, design §1–§4, §8).
 *
 * This class types shape, grammar, and per-collection identity uniqueness
 * only. It never resolves a reference into another collection and never
 * enforces a rule that needs more than the object currently being read —
 * those cross-collection/business rules are {@see ApplicationBlueprintValidator}'s
 * job, invoked by `SiteManifestParser::parse()` immediately after this class
 * returns. The one deliberate exception is `SITE047_BLUEPRINT_UNSUPPORTED_CONDITION`
 * (policy condition / check `kind`): resolving `kind` is a prerequisite for
 * choosing which closed per-kind shape to parse, so it cannot be deferred
 * without re-deriving the same enum twice.
 *
 * Not `@api`: this is the package's own structural parser, invoked only by
 * `SiteManifestParser`, not a public extension point.
 */
final class ApplicationBlueprintParser
{
    use ManifestShapeReader;

    private const string PERMISSION_GRAMMAR = '/^[a-z0-9_-]+( [a-z0-9_-]+)*$/D';

    public function parse(mixed $value, string $path, string $source): ApplicationBlueprint
    {
        $root = $this->shape(
            $value,
            ['contract_version', 'entities', 'relationships', 'permissions', 'roles', 'policies', 'workflows', 'fixtures', 'checks'],
            ['contract_version', 'entities', 'relationships', 'permissions', 'roles', 'policies', 'workflows', 'fixtures', 'checks'],
            $path,
            $source,
        );

        $contractVersion = $this->integer($root['contract_version'], $path . '/contract_version', $source);
        $entities = $this->entities($root['entities'], $path . '/entities', $source);
        $relationships = $this->relationships($root['relationships'], $path . '/relationships', $source);
        $permissions = $this->permissions($root['permissions'], $path . '/permissions', $source);
        $roles = $this->roles($root['roles'], $path . '/roles', $source);
        $policies = $this->policies($root['policies'], $path . '/policies', $source);
        $workflows = $this->workflows($root['workflows'], $path . '/workflows', $source);
        $fixtures = $this->fixtures($root['fixtures'], $entities, $relationships, $path . '/fixtures', $source);
        $checks = $this->checks($root['checks'], $path . '/checks', $source);

        $normalized = [
            'contract_version' => $contractVersion,
            'entities' => array_map(static fn(BlueprintEntity $entity): array => $entity->toArray(), array_values($entities)),
            'relationships' => array_map(static fn(BlueprintRelationship $relationship): array => $relationship->toArray(), array_values($relationships)),
            'permissions' => array_map(static fn(BlueprintPermission $permission): array => $permission->toArray(), array_values($permissions)),
            'roles' => array_map(static fn(BlueprintRole $role): array => $role->toArray(), array_values($roles)),
            'policies' => array_map(static fn(BlueprintPolicy $policy): array => $policy->toArray(), array_values($policies)),
            'workflows' => array_map(static fn(BlueprintWorkflow $workflow): array => $workflow->toArray(), array_values($workflows)),
            'fixtures' => array_map(static fn(BlueprintFixture $fixture): array => $fixture->toArray(), array_values($fixtures)),
            'checks' => array_map(static fn(BlueprintCheck $check): array => $check->toArray(), array_values($checks)),
        ];
        $canonicalJson = CanonicalJson::encode($normalized);

        return new ApplicationBlueprint(
            $contractVersion,
            $entities,
            $relationships,
            $permissions,
            $roles,
            $policies,
            $workflows,
            $fixtures,
            $checks,
            $canonicalJson,
            ApplicationBlueprint::computeDigest($normalized),
        );
    }

    /** @return array<string, BlueprintEntity> */
    private function entities(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source, false);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape(
                $item,
                ['id', 'label', 'storage', 'revisionable', 'translatable', 'keys', 'fields'],
                ['id', 'label', 'storage', 'revisionable', 'translatable', 'keys', 'fields'],
                $itemPath,
                $source,
            );
            $id = $this->id($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $label = $this->string($row['label'], $itemPath . '/label', $source);
            $storageValue = $this->string($row['storage'], $itemPath . '/storage', $source);
            $storage = BlueprintStorage::tryFrom($storageValue);
            if ($storage === null) {
                $this->fail($source, 'SITE014_INVALID_VALUE', $itemPath . '/storage', 'Unknown blueprint storage backend.');
            }
            $revisionable = $this->boolean($row['revisionable'], $itemPath . '/revisionable', $source);
            $translatable = $this->boolean($row['translatable'], $itemPath . '/translatable', $source);
            $fields = $this->fields($row['fields'], $itemPath . '/fields', $source);
            $keys = $this->entityKeys($row['keys'], $itemPath . '/keys', $source);

            $result[$id] = new BlueprintEntity($id, $label, $storage, $revisionable, $translatable, $keys, $fields);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function entityKeys(mixed $value, string $path, string $source): BlueprintEntityKeys
    {
        $row = $this->shape(
            $value,
            ['id', 'uuid', 'label', 'revision', 'langcode', 'default_langcode', 'owner'],
            ['id', 'uuid', 'label'],
            $path,
            $source,
        );

        return new BlueprintEntityKeys(
            $this->id($row['id'], $path . '/id', $source),
            $this->id($row['uuid'], $path . '/uuid', $source),
            $this->id($row['label'], $path . '/label', $source),
            array_key_exists('revision', $row) ? $this->id($row['revision'], $path . '/revision', $source) : null,
            array_key_exists('langcode', $row) ? $this->id($row['langcode'], $path . '/langcode', $source) : null,
            array_key_exists('default_langcode', $row) ? $this->id($row['default_langcode'], $path . '/default_langcode', $source) : null,
            array_key_exists('owner', $row) ? $this->id($row['owner'], $path . '/owner', $source) : null,
        );
    }

    /** @return array<string, BlueprintField> */
    private function fields(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape(
                $item,
                ['id', 'type', 'required', 'cardinality', 'translatable', 'revisionable', 'indexed', 'values'],
                ['id', 'type'],
                $itemPath,
                $source,
            );
            $id = $this->id($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $typeValue = $this->string($row['type'], $itemPath . '/type', $source);
            $type = BlueprintFieldType::tryFrom($typeValue);
            if ($type === null) {
                $this->fail($source, 'SITE014_INVALID_VALUE', $itemPath . '/type', 'Unknown blueprint field type.');
            }
            $required = array_key_exists('required', $row) ? $this->boolean($row['required'], $itemPath . '/required', $source) : false;
            $cardinality = array_key_exists('cardinality', $row) ? $this->cardinality($row['cardinality'], $itemPath . '/cardinality', $source) : 1;
            $translatable = array_key_exists('translatable', $row) ? $this->boolean($row['translatable'], $itemPath . '/translatable', $source) : false;
            $revisionable = array_key_exists('revisionable', $row) ? $this->boolean($row['revisionable'], $itemPath . '/revisionable', $source) : false;
            $indexed = array_key_exists('indexed', $row) ? $this->boolean($row['indexed'], $itemPath . '/indexed', $source) : false;
            $values = array_key_exists('values', $row)
                ? $this->sortedUnique($this->stringList($row['values'], $itemPath . '/values', $source, false))
                : null;

            $result[$id] = new BlueprintField($id, $type, $required, $cardinality, $translatable, $revisionable, $indexed, $values);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, BlueprintRelationship> */
    private function relationships(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape(
                $item,
                ['id', 'from', 'to', 'cardinality', 'required', 'on_delete'],
                ['id', 'from', 'to'],
                $itemPath,
                $source,
            );
            $id = $this->id($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $from = $this->shape($row['from'], ['entity', 'field'], ['entity', 'field'], $itemPath . '/from', $source);
            $to = $this->shape($row['to'], ['entity'], ['entity'], $itemPath . '/to', $source);
            $fromEntity = $this->id($from['entity'], $itemPath . '/from/entity', $source);
            $fromField = $this->id($from['field'], $itemPath . '/from/field', $source);
            $toEntity = $this->id($to['entity'], $itemPath . '/to/entity', $source);
            $cardinality = array_key_exists('cardinality', $row) ? $this->cardinality($row['cardinality'], $itemPath . '/cardinality', $source) : 1;
            $required = array_key_exists('required', $row) ? $this->boolean($row['required'], $itemPath . '/required', $source) : false;
            $onDelete = BlueprintOnDelete::Restrict;
            if (array_key_exists('on_delete', $row)) {
                $onDeleteValue = $this->string($row['on_delete'], $itemPath . '/on_delete', $source);
                $resolved = BlueprintOnDelete::tryFrom($onDeleteValue);
                if ($resolved === null) {
                    $this->fail($source, 'SITE014_INVALID_VALUE', $itemPath . '/on_delete', 'Unknown blueprint on-delete behaviour.');
                }
                $onDelete = $resolved;
            }

            $result[$id] = new BlueprintRelationship($id, $fromEntity, $fromField, $toEntity, $cardinality, $required, $onDelete);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, BlueprintPermission> */
    private function permissions(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape($item, ['id', 'title'], ['id', 'title'], $itemPath, $source);
            $id = $this->permissionId($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $title = $this->string($row['title'], $itemPath . '/title', $source);

            $result[$id] = new BlueprintPermission($id, $title);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, BlueprintRole> */
    private function roles(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape($item, ['id', 'label', 'permissions'], ['id', 'label', 'permissions'], $itemPath, $source);
            $id = $this->id($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $label = $this->string($row['label'], $itemPath . '/label', $source);
            $permissions = $this->sortedUnique($this->permissionIdList($row['permissions'], $itemPath . '/permissions', $source));

            $result[$id] = new BlueprintRole($id, $label, $permissions);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, BlueprintPolicy> */
    private function policies(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape($item, ['id', 'entity', 'operation', 'condition'], ['id', 'entity', 'operation', 'condition'], $itemPath, $source);
            $id = $this->id($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $entity = $this->id($row['entity'], $itemPath . '/entity', $source);
            $operationValue = $this->string($row['operation'], $itemPath . '/operation', $source);
            $operation = BlueprintOperation::tryFrom($operationValue);
            if ($operation === null) {
                $this->fail($source, 'SITE014_INVALID_VALUE', $itemPath . '/operation', 'Unknown blueprint operation.');
            }
            $condition = $this->policyCondition($row['condition'], $itemPath . '/condition', $source);

            $result[$id] = new BlueprintPolicy($id, $entity, $operation, $condition);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function policyCondition(mixed $value, string $path, string $source): BlueprintPolicyCondition
    {
        $probe = $this->shape($value, ['kind', 'permission', 'states'], ['kind'], $path, $source, false);
        $kindValue = $this->string($probe['kind'], $path . '/kind', $source);
        $kind = BlueprintConditionKind::tryFrom($kindValue);
        if ($kind === null) {
            $this->fail($source, 'SITE047_BLUEPRINT_UNSUPPORTED_CONDITION', $path . '/kind', 'Unsupported policy condition kind.');
        }

        return match ($kind) {
            BlueprintConditionKind::Permission, BlueprintConditionKind::Ownership => (function () use ($value, $path, $source, $kind): BlueprintPolicyCondition {
                $row = $this->shape($value, ['kind', 'permission'], ['kind', 'permission'], $path, $source);

                return new BlueprintPolicyCondition($kind, $this->permissionId($row['permission'], $path . '/permission', $source));
            })(),
            BlueprintConditionKind::WorkflowState => (function () use ($value, $path, $source, $kind): BlueprintPolicyCondition {
                $row = $this->shape($value, ['kind', 'permission', 'states'], ['kind', 'permission', 'states'], $path, $source);
                $states = $this->sortedUnique($this->idList($row['states'], $path . '/states', $source, false));

                return new BlueprintPolicyCondition($kind, $this->permissionId($row['permission'], $path . '/permission', $source), $states);
            })(),
        };
    }

    /** @return array<string, BlueprintWorkflow> */
    private function workflows(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape(
                $item,
                ['id', 'label', 'initial_state', 'states', 'transitions', 'bindings'],
                ['id', 'label', 'initial_state', 'states', 'transitions', 'bindings'],
                $itemPath,
                $source,
            );
            $id = $this->id($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $label = $this->string($row['label'], $itemPath . '/label', $source);
            $initialState = $this->id($row['initial_state'], $itemPath . '/initial_state', $source);
            $states = $this->workflowStates($row['states'], $itemPath . '/states', $source);
            $transitions = $this->workflowTransitions($row['transitions'], $itemPath . '/transitions', $source);
            $bindings = $this->workflowBindings($row['bindings'], $itemPath . '/bindings', $source);

            $result[$id] = new BlueprintWorkflow($id, $label, $initialState, $states, $transitions, $bindings);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, BlueprintWorkflowState> */
    private function workflowStates(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source, false);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape($item, ['id', 'label', 'published'], ['id', 'label', 'published'], $itemPath, $source);
            $id = $this->id($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $label = $this->string($row['label'], $itemPath . '/label', $source);
            $published = $this->boolean($row['published'], $itemPath . '/published', $source);

            $result[$id] = new BlueprintWorkflowState($id, $label, $published);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, BlueprintWorkflowTransition> */
    private function workflowTransitions(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape($item, ['id', 'label', 'from', 'to', 'permission'], ['id', 'label', 'from', 'to', 'permission'], $itemPath, $source);
            $id = $this->id($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $label = $this->string($row['label'], $itemPath . '/label', $source);
            $from = $this->sortedUnique($this->idList($row['from'], $itemPath . '/from', $source, false));
            $to = $this->id($row['to'], $itemPath . '/to', $source);
            $permission = $this->permissionId($row['permission'], $itemPath . '/permission', $source);

            $result[$id] = new BlueprintWorkflowTransition($id, $label, $from, $to, $permission);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return list<BlueprintWorkflowBinding> */
    private function workflowBindings(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape($item, ['entity'], ['entity'], $itemPath, $source);
            $result[] = new BlueprintWorkflowBinding($this->id($row['entity'], $itemPath . '/entity', $source));
        }
        usort($result, static fn(BlueprintWorkflowBinding $a, BlueprintWorkflowBinding $b): int => $a->entity <=> $b->entity);

        return $result;
    }

    /**
     * @param array<string, BlueprintEntity> $entities
     * @param array<string, BlueprintRelationship> $relationships
     * @return array<string, BlueprintFixture>
     */
    private function fixtures(mixed $value, array $entities, array $relationships, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $row = $this->shape($item, ['id', 'entity', 'values', 'workflow_state'], ['id', 'entity', 'values'], $itemPath, $source);
            $id = $this->id($row['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $entity = $this->id($row['entity'], $itemPath . '/entity', $source);
            $values = $this->fixtureValues($row['values'], $itemPath . '/values', $source);
            $workflowState = array_key_exists('workflow_state', $row)
                ? $this->id($row['workflow_state'], $itemPath . '/workflow_state', $source)
                : null;

            $result[$id] = new BlueprintFixture($id, $entity, $values, $workflowState);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, string|int|float|bool|null|list<string|int|float|bool|null>> */
    private function fixtureValues(mixed $value, string $path, string $source): array
    {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a mapping of field ids to values.');
        }

        $result = [];
        foreach ($value as $key => $item) {
            $itemPath = $path . '/' . $this->pointer((string) $key);
            $fieldId = $this->id($key, $itemPath, $source);
            if (is_array($item)) {
                if (!array_is_list($item)) {
                    $this->fail($source, 'SITE010_INVALID_TYPE', $itemPath, 'Expected a scalar or a list of scalars.');
                }
                $list = [];
                foreach ($item as $scalarIndex => $scalar) {
                    $list[] = $this->scalar($scalar, $itemPath . '/' . $scalarIndex, $source);
                }
                $result[$fieldId] = $list;
            } else {
                $result[$fieldId] = $this->scalar($item, $itemPath, $source);
            }
        }

        return $result;
    }

    private function scalar(mixed $value, string $path, string $source): string|int|float|bool|null
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a scalar fixture value.');
        }

        return $value;
    }

    /** @return array<string, BlueprintCheck> */
    private function checks(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $itemPath = $path . '/' . $index;
            $probe = $this->shape(
                $item,
                ['id', 'kind', 'role', 'permission', 'workflow', 'transition', 'entity', 'operation', 'fixture', 'expect'],
                ['id', 'kind'],
                $itemPath,
                $source,
                false,
            );
            $id = $this->id($probe['id'], $itemPath . '/id', $source);
            $this->assertUniqueId($result, $id, $itemPath . '/id', $source);
            $kindValue = $this->string($probe['kind'], $itemPath . '/kind', $source);
            $kind = BlueprintCheckKind::tryFrom($kindValue);
            if ($kind === null) {
                $this->fail($source, 'SITE047_BLUEPRINT_UNSUPPORTED_CONDITION', $itemPath . '/kind', 'Unsupported check kind.');
            }

            $result[$id] = match ($kind) {
                BlueprintCheckKind::RolePermission => $this->rolePermissionCheck($item, $itemPath, $source, $id),
                BlueprintCheckKind::WorkflowTransition => $this->workflowTransitionCheck($item, $itemPath, $source, $id),
                BlueprintCheckKind::EntityAccess => $this->entityAccessCheck($item, $itemPath, $source, $id),
                BlueprintCheckKind::FixturePresent => $this->fixturePresentCheck($item, $itemPath, $source, $id),
            };
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function rolePermissionCheck(mixed $item, string $path, string $source, string $id): BlueprintCheck
    {
        $row = $this->shape($item, ['id', 'kind', 'role', 'permission', 'expect'], ['id', 'kind', 'role', 'permission', 'expect'], $path, $source);
        $role = $this->id($row['role'], $path . '/role', $source);
        $permission = $this->permissionId($row['permission'], $path . '/permission', $source);
        $expect = $this->stringEnum($row['expect'], ['granted', 'denied'], $path . '/expect', $source);

        return new BlueprintCheck($id, BlueprintCheckKind::RolePermission, role: $role, permission: $permission, expect: $expect);
    }

    private function workflowTransitionCheck(mixed $item, string $path, string $source, string $id): BlueprintCheck
    {
        $row = $this->shape($item, ['id', 'kind', 'role', 'workflow', 'transition', 'expect'], ['id', 'kind', 'role', 'workflow', 'transition', 'expect'], $path, $source);
        $role = $this->id($row['role'], $path . '/role', $source);
        $workflow = $this->id($row['workflow'], $path . '/workflow', $source);
        $transition = $this->id($row['transition'], $path . '/transition', $source);
        $expect = $this->stringEnum($row['expect'], ['allowed', 'denied'], $path . '/expect', $source);

        return new BlueprintCheck($id, BlueprintCheckKind::WorkflowTransition, role: $role, workflow: $workflow, transition: $transition, expect: $expect);
    }

    private function entityAccessCheck(mixed $item, string $path, string $source, string $id): BlueprintCheck
    {
        $row = $this->shape($item, ['id', 'kind', 'role', 'entity', 'operation', 'fixture', 'expect'], ['id', 'kind', 'role', 'entity', 'operation', 'expect'], $path, $source);
        $role = $this->id($row['role'], $path . '/role', $source);
        $entity = $this->id($row['entity'], $path . '/entity', $source);
        $operationValue = $this->string($row['operation'], $path . '/operation', $source);
        $operation = BlueprintOperation::tryFrom($operationValue);
        if ($operation === null) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path . '/operation', 'Unknown blueprint operation.');
        }
        $fixture = array_key_exists('fixture', $row) ? $this->id($row['fixture'], $path . '/fixture', $source) : null;
        $expect = $this->stringEnum($row['expect'], ['allow', 'deny'], $path . '/expect', $source);

        return new BlueprintCheck($id, BlueprintCheckKind::EntityAccess, role: $role, entity: $entity, operation: $operation, fixture: $fixture, expect: $expect);
    }

    private function fixturePresentCheck(mixed $item, string $path, string $source, string $id): BlueprintCheck
    {
        $row = $this->shape($item, ['id', 'kind', 'fixture'], ['id', 'kind', 'fixture'], $path, $source);
        $fixture = $this->id($row['fixture'], $path . '/fixture', $source);

        return new BlueprintCheck($id, BlueprintCheckKind::FixturePresent, fixture: $fixture);
    }

    private function cardinality(mixed $value, string $path, string $source): int
    {
        $integer = $this->integer($value, $path, $source);
        if ($integer !== -1 && $integer < 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a positive integer cardinality, or -1 for unlimited.');
        }

        return $integer;
    }

    private function permissionId(mixed $value, string $path, string $source): string
    {
        $permission = $this->string($value, $path, $source);
        if (str_contains($permission, '*')) {
            $this->fail($source, 'SITE045_BLUEPRINT_WILDCARD_PERMISSION', $path, 'A wildcard permission is not permitted.');
        }
        if (preg_match(self::PERMISSION_GRAMMAR, $permission) !== 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a lowercase space-separated permission identity.');
        }

        return $permission;
    }

    /** @return list<string> */
    private function permissionIdList(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $seen = [];
        foreach ($rows as $index => $row) {
            $item = $this->permissionId($row, $path . '/' . $index, $source);
            if (isset($seen[$item])) {
                $this->fail($source, 'SITE021_DUPLICATE_VALUE', $path . '/' . $index, 'List values must be unique.');
            }
            $seen[$item] = true;
            $rows[$index] = $item;
        }

        return $rows;
    }

    /** @return list<string> */
    private function idList(mixed $value, string $path, string $source, bool $allowEmpty = true): array
    {
        $rows = $this->list($value, $path, $source, $allowEmpty);
        $seen = [];
        foreach ($rows as $index => $row) {
            $item = $this->id($row, $path . '/' . $index, $source);
            if (isset($seen[$item])) {
                $this->fail($source, 'SITE021_DUPLICATE_VALUE', $path . '/' . $index, 'List values must be unique.');
            }
            $seen[$item] = true;
            $rows[$index] = $item;
        }

        return $rows;
    }

    private function stringEnum(mixed $value, array $allowed, string $path, string $source): string
    {
        $string = $this->string($value, $path, $source);
        if (!in_array($string, $allowed, true)) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected one of: ' . implode(', ', $allowed) . '.');
        }

        return $string;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
