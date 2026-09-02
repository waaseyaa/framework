<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprintSchema;

/** @api */
final class SiteManifestSchema
{
    public const int CURRENT_VERSION = 1;

    /** @return array<string, mixed> */
    public static function document(): array
    {
        $id = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]*$'];
        $nonEmpty = ['type' => 'string', 'minLength' => 1];
        $stringList = ['type' => 'array', 'items' => $nonEmpty, 'uniqueItems' => true];
        $nonEmptyStringList = $stringList + ['minItems' => 1];
        $route = ['type' => 'string', 'pattern' => '^(?!.*(?:^|/)\\.{1,2}(?:/|$))/(?!/)[^\\x00-\\x20\\x7F?#\\\\%]*$'];
        $routeList = ['type' => 'array', 'items' => $route, 'uniqueItems' => true];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://waaseyaa.org/schema/site/v1',
            'title' => 'Waaseyaa site capability manifest',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema', 'version', 'generator_version', 'application', 'framework', 'content_types', 'capabilities', 'personal_data_stores', 'recipes', 'verification'],
            'properties' => [
                'schema' => ['const' => 'waaseyaa.site'],
                'version' => ['const' => self::CURRENT_VERSION],
                'generator_version' => ['type' => 'integer', 'minimum' => 1],
                'application' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'name', 'canonical_origin'],
                    'properties' => [
                        'id' => $id,
                        'name' => $nonEmpty,
                        'canonical_origin' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['config_key'],
                            'properties' => ['config_key' => ['type' => 'string', 'pattern' => '^[A-Z][A-Z0-9_]*$']],
                        ],
                    ],
                ],
                'framework' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['revision_policy', 'observed_lock_sha256'],
                    'properties' => [
                        'revision_policy' => ['const' => 'exact-lock'],
                        'observed_lock_sha256' => ['$ref' => '#/$defs/sha256'],
                    ],
                ],
                'content_types' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'canonical_route'],
                        'properties' => ['id' => $id, 'canonical_route' => $route],
                    ],
                ],
                'capabilities' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'oneOf' => [
                            ['$ref' => '#/$defs/activeCapability'],
                            ['$ref' => '#/$defs/plannedCapability'],
                            ['$ref' => '#/$defs/notNeededCapability'],
                        ],
                    ],
                ],
                'personal_data_stores' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'classification', 'consent_operation', 'retention', 'export_operation', 'deletion_operation'],
                        'properties' => [
                            'id' => $id,
                            'classification' => $nonEmpty,
                            'consent_operation' => $nonEmpty,
                            'retention' => $nonEmpty,
                            'export_operation' => $nonEmpty,
                            'deletion_operation' => $nonEmpty,
                        ],
                    ],
                ],
                'recipes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'version', 'capability', 'artifact_digest'],
                        'properties' => [
                            'id' => $id,
                            'version' => ['type' => 'integer', 'minimum' => 1],
                            'capability' => $id,
                            'artifact_digest' => ['$ref' => '#/$defs/sha256'],
                        ],
                    ],
                ],
                'verification' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['command'],
                    'properties' => ['command' => ['const' => 'bin/maintenance/site-verify']],
                ],
                'application_blueprint' => ['$ref' => '#/$defs/applicationBlueprint'],
            ],
            '$defs' => self::blueprintDefs() + [
                'sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'activeCapability' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'state', 'package', 'provider', 'configuration_authority', 'public_routes', 'data_classification', 'lifecycle', 'verification'],
                    'properties' => [
                        'id' => $id,
                        'state' => ['const' => 'active'],
                        'package' => ['type' => 'string', 'pattern' => '^[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]?|-{0,2})[a-z0-9]+)*$'],
                        'provider' => $nonEmpty,
                        'configuration_authority' => $nonEmpty,
                        'public_routes' => $routeList,
                        'data_classification' => $nonEmpty,
                        'lifecycle' => $nonEmptyStringList,
                        'verification' => $nonEmptyStringList,
                    ],
                ],
                'plannedCapability' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'state', 'note'],
                    'properties' => ['id' => $id, 'state' => ['const' => 'planned'], 'note' => $nonEmpty],
                ],
                'notNeededCapability' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'state', 'reason'],
                    'properties' => ['id' => $id, 'state' => ['const' => 'not_needed'], 'reason' => $nonEmpty],
                ],
            ],
        ];
    }

    public static function canonicalJson(): string
    {
        return CanonicalJson::encode(self::document());
    }

    /**
     * The blueprint fragment's own `$defs` extracted and merged into this
     * document's root `$defs`, plus an `applicationBlueprint` entry holding
     * everything else. JSON Schema `$ref` resolves `#/$defs/...` against the
     * document root, not the referencing subschema, so a nested `$defs` under
     * `applicationBlueprint` would be unreachable from the fragment's own
     * internal `$ref`s.
     *
     * @return array<string, mixed>
     */
    private static function blueprintDefs(): array
    {
        $fragment = ApplicationBlueprintSchema::fragment();
        $nested = $fragment['$defs'];
        unset($fragment['$defs']);

        return $nested + ['applicationBlueprint' => $fragment];
    }
}
