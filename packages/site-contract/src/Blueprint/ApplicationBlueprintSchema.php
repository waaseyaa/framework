<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The closed JSON Schema (draft 2020-12) fragment for the optional
 * `application_blueprint` section (#2785, design §8). Composed into
 * {@see \Waaseyaa\SiteContract\SiteManifestSchema::document()} as an optional
 * `application_blueprint` property.
 *
 * This schema is a documentation/tooling artifact. The single behavioural
 * authority is `ApplicationBlueprintParser` + `ApplicationBlueprintValidator`;
 * this fragment must not drift from their closed shapes, but it does not
 * itself replace them.
 *
 * Not `@api`: composed only by `SiteManifestSchema`.
 */
final class ApplicationBlueprintSchema
{
    /** @return array<string, mixed> */
    public static function fragment(): array
    {
        $blueprintId = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]*$'];
        $permissionId = ['type' => 'string', 'pattern' => '^[a-z0-9_-]+( [a-z0-9_-]+)*$'];
        $nonEmpty = ['type' => 'string', 'minLength' => 1];
        $idList = ['type' => 'array', 'items' => $blueprintId, 'uniqueItems' => true];
        $nonEmptyIdList = $idList + ['minItems' => 1];
        $permissionIdList = ['type' => 'array', 'items' => $permissionId, 'uniqueItems' => true];
        $cardinality = ['type' => 'integer', 'anyOf' => [['const' => -1], ['minimum' => 1]]];
        $scalar = ['type' => ['string', 'integer', 'number', 'boolean', 'null']];
        $scalarOrScalarList = ['anyOf' => [$scalar, ['type' => 'array', 'items' => $scalar]]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['contract_version', 'entities', 'relationships', 'permissions', 'roles', 'policies', 'workflows', 'fixtures', 'checks'],
            'properties' => [
                'contract_version' => ['const' => ApplicationBlueprint::CONTRACT_VERSION],
                'entities' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => ['$ref' => '#/$defs/blueprintEntity'],
                ],
                'relationships' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintRelationship']],
                'permissions' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintPermission']],
                'roles' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintRole']],
                'policies' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintPolicy']],
                'workflows' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintWorkflow']],
                'fixtures' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintFixture']],
                'checks' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintCheck']],
            ],
            '$defs' => [
                'blueprintEntity' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'label', 'storage', 'revisionable', 'translatable', 'keys', 'fields'],
                    'properties' => [
                        'id' => $blueprintId,
                        'label' => $nonEmpty,
                        'storage' => ['enum' => array_map(static fn(BlueprintStorage $case): string => $case->value, BlueprintStorage::cases())],
                        'revisionable' => ['type' => 'boolean'],
                        'translatable' => ['type' => 'boolean'],
                        'keys' => ['$ref' => '#/$defs/blueprintEntityKeys'],
                        'fields' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintField']],
                    ],
                ],
                'blueprintEntityKeys' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'uuid', 'label'],
                    'properties' => [
                        'id' => $blueprintId,
                        'uuid' => $blueprintId,
                        'label' => $blueprintId,
                        'revision' => $blueprintId,
                        'langcode' => $blueprintId,
                        'default_langcode' => $blueprintId,
                        'owner' => $blueprintId,
                    ],
                ],
                'blueprintField' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'type'],
                    'properties' => [
                        'id' => $blueprintId,
                        'type' => ['enum' => array_map(static fn(BlueprintFieldType $case): string => $case->value, BlueprintFieldType::cases())],
                        'required' => ['type' => 'boolean'],
                        'cardinality' => $cardinality,
                        'translatable' => ['type' => 'boolean'],
                        'revisionable' => ['type' => 'boolean'],
                        'indexed' => ['type' => 'boolean'],
                        'values' => $nonEmptyIdList + ['items' => $nonEmpty, 'uniqueItems' => true],
                    ],
                ],
                'blueprintRelationship' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'from', 'to'],
                    'properties' => [
                        'id' => $blueprintId,
                        'from' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['entity', 'field'],
                            'properties' => ['entity' => $blueprintId, 'field' => $blueprintId],
                        ],
                        'to' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['entity'],
                            'properties' => ['entity' => $blueprintId],
                        ],
                        'cardinality' => $cardinality,
                        'required' => ['type' => 'boolean'],
                        'on_delete' => ['enum' => array_map(static fn(BlueprintOnDelete $case): string => $case->value, BlueprintOnDelete::cases())],
                    ],
                ],
                'blueprintPermission' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'title'],
                    'properties' => ['id' => $permissionId, 'title' => $nonEmpty],
                ],
                'blueprintRole' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'label', 'permissions'],
                    'properties' => ['id' => $blueprintId, 'label' => $nonEmpty, 'permissions' => $permissionIdList],
                ],
                'blueprintPolicy' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'entity', 'operation', 'condition'],
                    'properties' => [
                        'id' => $blueprintId,
                        'entity' => $blueprintId,
                        'operation' => ['enum' => array_map(static fn(BlueprintOperation $case): string => $case->value, BlueprintOperation::cases())],
                        'condition' => [
                            'oneOf' => [
                                ['$ref' => '#/$defs/permissionCondition'],
                                ['$ref' => '#/$defs/ownershipCondition'],
                                ['$ref' => '#/$defs/workflowStateCondition'],
                            ],
                        ],
                    ],
                ],
                'permissionCondition' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['kind', 'permission'],
                    'properties' => ['kind' => ['const' => BlueprintConditionKind::Permission->value], 'permission' => $permissionId],
                ],
                'ownershipCondition' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['kind', 'permission'],
                    'properties' => ['kind' => ['const' => BlueprintConditionKind::Ownership->value], 'permission' => $permissionId],
                ],
                'workflowStateCondition' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['kind', 'permission', 'states'],
                    'properties' => ['kind' => ['const' => BlueprintConditionKind::WorkflowState->value], 'permission' => $permissionId, 'states' => $nonEmptyIdList],
                ],
                'blueprintWorkflow' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'label', 'initial_state', 'states', 'transitions', 'bindings'],
                    'properties' => [
                        'id' => $blueprintId,
                        'label' => $nonEmpty,
                        'initial_state' => $blueprintId,
                        'states' => ['type' => 'array', 'minItems' => 1, 'items' => ['$ref' => '#/$defs/blueprintWorkflowState']],
                        'transitions' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintWorkflowTransition']],
                        'bindings' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/blueprintWorkflowBinding']],
                    ],
                ],
                'blueprintWorkflowState' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'label', 'published'],
                    'properties' => ['id' => $blueprintId, 'label' => $nonEmpty, 'published' => ['type' => 'boolean']],
                ],
                'blueprintWorkflowTransition' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'label', 'from', 'to', 'permission'],
                    'properties' => [
                        'id' => $blueprintId,
                        'label' => $nonEmpty,
                        'from' => $nonEmptyIdList,
                        'to' => $blueprintId,
                        'permission' => $permissionId,
                    ],
                ],
                'blueprintWorkflowBinding' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['entity'],
                    'properties' => ['entity' => $blueprintId],
                ],
                // `values` is intentionally loose here: JSON Schema cannot express
                // "this key's allowed shape depends on a sibling entity's field/
                // relationship declarations elsewhere in the document" (a
                // cross-reference, not a static shape). The full rule —
                // cardinality-shape (scalar vs. list), scalar-type-by-field-type,
                // enum-membership, and required/null handling — is enforced only
                // by ApplicationBlueprintValidator::checkFixtures() (design §1,
                // §"Governed application blueprints" in site-golden-path.md).
                // This schema fragment is documentation-grade, never a second
                // validation authority.
                'blueprintFixture' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'entity', 'values'],
                    'properties' => [
                        'id' => $blueprintId,
                        'entity' => $blueprintId,
                        'values' => ['type' => 'object', 'propertyNames' => $blueprintId, 'additionalProperties' => $scalarOrScalarList],
                        'workflow_state' => $blueprintId,
                    ],
                ],
                'blueprintCheck' => [
                    'oneOf' => [
                        ['$ref' => '#/$defs/rolePermissionCheck'],
                        ['$ref' => '#/$defs/workflowTransitionCheck'],
                        ['$ref' => '#/$defs/entityAccessCheck'],
                        ['$ref' => '#/$defs/fixturePresentCheck'],
                    ],
                ],
                'rolePermissionCheck' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'kind', 'role', 'permission', 'expect'],
                    'properties' => [
                        'id' => $blueprintId,
                        'kind' => ['const' => BlueprintCheckKind::RolePermission->value],
                        'role' => $blueprintId,
                        'permission' => $permissionId,
                        'expect' => ['enum' => ['granted', 'denied']],
                    ],
                ],
                'workflowTransitionCheck' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'kind', 'role', 'workflow', 'transition', 'expect'],
                    'properties' => [
                        'id' => $blueprintId,
                        'kind' => ['const' => BlueprintCheckKind::WorkflowTransition->value],
                        'role' => $blueprintId,
                        'workflow' => $blueprintId,
                        'transition' => $blueprintId,
                        'expect' => ['enum' => ['allowed', 'denied']],
                    ],
                ],
                'entityAccessCheck' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'kind', 'role', 'entity', 'operation', 'expect'],
                    'properties' => [
                        'id' => $blueprintId,
                        'kind' => ['const' => BlueprintCheckKind::EntityAccess->value],
                        'role' => $blueprintId,
                        'entity' => $blueprintId,
                        'operation' => ['enum' => array_map(static fn(BlueprintOperation $case): string => $case->value, BlueprintOperation::cases())],
                        'fixture' => $blueprintId,
                        'expect' => ['enum' => ['allow', 'deny']],
                    ],
                ],
                'fixturePresentCheck' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'kind', 'fixture'],
                    'properties' => [
                        'id' => $blueprintId,
                        'kind' => ['const' => BlueprintCheckKind::FixturePresent->value],
                        'fixture' => $blueprintId,
                    ],
                ],
            ],
        ];
    }
}
