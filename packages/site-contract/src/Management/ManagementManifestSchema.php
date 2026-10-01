<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Management;

use Waaseyaa\SiteContract\CanonicalJson;

/** Machine-readable companion shape; embedded operation schemas remain product-owned. @api */
final class ManagementManifestSchema
{
    public const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';
    public const array EFFECTS = ['read', 'create', 'update', 'delete', 'publish', 'execute', 'provision', 'revoke'];
    public const array SEMANTICS = [
        'tenant_scope' => ['required', 'system'],
        'idempotency' => ['none', 'key', 'natural'],
        'concurrency' => ['none', 'etag', 'revision', 'generation'],
        'dry_run' => ['supported', 'unsupported', 'not_applicable'],
        'audit' => ['durable', 'best_effort', 'none'],
        'approval' => ['required', 'not_required'],
    ];

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $string = ['type' => 'string', 'minLength' => 1, 'pattern' => '^\\S(?:.*\\S)?$'];
        $list = ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => $string];
        $identity = ['type' => 'string', 'minLength' => 1, 'pattern' => '^[A-Za-z][A-Za-z0-9_.:-]*$'];
        $schema = ['type' => 'object', 'required' => ['$schema', 'type'], 'properties' => [
            '$schema' => ['const' => self::DIALECT], 'type' => ['const' => 'object']], 'additionalProperties' => true];
        $properties = [
            'capability' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]*$'],
            'binding' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['transport', 'name'],
                'properties' => ['transport' => ['enum' => ['api', 'mcp', 'cli']], 'name' => $string]],
            'input_schema' => $schema, 'output_schema' => $schema, 'required_scopes' => $list,
            'effects' => ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => ['enum' => self::EFFECTS]],
            'verification' => $list,
        ];
        foreach (self::SEMANTICS as $key => $values) {
            $properties[$key] = ['enum' => $values];
        }
        $contract = ['type' => 'object', 'additionalProperties' => false,
            'required' => array_keys($properties), 'properties' => $properties];
        $supported = ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'state', 'contract'],
            'properties' => ['id' => $identity, 'state' => ['const' => 'supported'], 'contract' => $contract]];
        $unavailable = ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'state', 'reason'],
            'properties' => ['id' => $identity, 'state' => ['enum' => ['planned', 'unsupported']], 'reason' => $string]];

        return ['$schema' => self::DIALECT, '$id' => 'urn:waaseyaa:management:1', 'title' => 'Waaseyaa management companion v1',
            'type' => 'object', 'additionalProperties' => false, 'required' => ['schema', 'version', 'application', 'operations'],
            'properties' => ['schema' => ['const' => 'waaseyaa.management'], 'version' => ['const' => 1],
                'application' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]*$'],
                'operations' => ['type' => 'array', 'minItems' => 1, 'items' => ['oneOf' => [$supported, $unavailable]]]]];
    }

    public static function canonicalJson(): string
    {
        return CanonicalJson::encode(self::schema());
    }
}
