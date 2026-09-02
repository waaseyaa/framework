<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

use Waaseyaa\SiteContract\CanonicalJson;

/**
 * The typed, normalized `application_blueprint` section of a `waaseyaa.site`
 * v1 manifest (#2785, ADR-023).
 *
 * The constructor parameters carry AUTHORED order (insertion order, exactly
 * as encountered in the YAML list) so that `ApplicationBlueprintValidator`'s
 * incrementing-index JSON Pointers name the authored position of an offending
 * entry, not a canonicalized one. Canonical (sorted-by-id) order is applied
 * only in {@see self::toArray()}, which is the sole source of the normalized
 * section, its canonical JSON, and its digest — see the constructor body.
 *
 * @api
 */
final readonly class ApplicationBlueprint
{
    /**
     * Presence of the section derives this required generator feature token
     * (ADR-023 D-2). It is never authored inside the section itself.
     */
    public const string GENERATOR_FEATURE = 'site-application-blueprint-v1';

    /** The fixed schema id the {@see self::$digest} formula is computed over (§5). */
    public const string SCHEMA_ID = 'waaseyaa.application_blueprint';

    /** The only contract version this runtime accepts (SITE040 otherwise). */
    public const int CONTRACT_VERSION = 1;

    public string $canonicalJson;
    public string $digest;

    /**
     * @param array<string, BlueprintEntity> $entities authored order
     * @param array<string, BlueprintRelationship> $relationships authored order
     * @param array<string, BlueprintPermission> $permissions authored order
     * @param array<string, BlueprintRole> $roles authored order
     * @param array<string, BlueprintPolicy> $policies authored order
     * @param array<string, BlueprintWorkflow> $workflows authored order
     * @param array<string, BlueprintFixture> $fixtures authored order
     * @param array<string, BlueprintCheck> $checks authored order
     */
    public function __construct(
        public int $contractVersion,
        public array $entities,
        public array $relationships,
        public array $permissions,
        public array $roles,
        public array $policies,
        public array $workflows,
        public array $fixtures,
        public array $checks,
    ) {
        $normalized = $this->toArray();
        $this->canonicalJson = CanonicalJson::encode($normalized);
        $this->digest = self::computeDigest($normalized);
    }

    /**
     * The normalized SECTION as it appears in the manifest: every id-keyed
     * collection sorted by id (§4). Authored order on the constructor
     * properties is preserved for validator addressing; this method is the
     * only place canonical order is applied.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_version' => $this->contractVersion,
            'entities' => array_map(static fn(BlueprintEntity $entity): array => $entity->toArray(), array_values(self::sortedById($this->entities))),
            'relationships' => array_map(static fn(BlueprintRelationship $relationship): array => $relationship->toArray(), array_values(self::sortedById($this->relationships))),
            'permissions' => array_map(static fn(BlueprintPermission $permission): array => $permission->toArray(), array_values(self::sortedById($this->permissions))),
            'roles' => array_map(static fn(BlueprintRole $role): array => $role->toArray(), array_values(self::sortedById($this->roles))),
            'policies' => array_map(static fn(BlueprintPolicy $policy): array => $policy->toArray(), array_values(self::sortedById($this->policies))),
            'workflows' => array_map(static fn(BlueprintWorkflow $workflow): array => $workflow->toArray(), array_values(self::sortedById($this->workflows))),
            'fixtures' => array_map(static fn(BlueprintFixture $fixture): array => $fixture->toArray(), array_values(self::sortedById($this->fixtures))),
            'checks' => array_map(static fn(BlueprintCheck $check): array => $check->toArray(), array_values(self::sortedById($this->checks))),
        ];
    }

    /**
     * @template T
     * @param array<string, T> $items
     * @return array<string, T>
     */
    private static function sortedById(array $items): array
    {
        ksort($items, SORT_STRING);

        return $items;
    }

    /**
     * The blueprint digest formula (§5): sha256 over the canonical JSON of
     * `{schema, contract_version, payload}` where `payload` is the normalized
     * section WITHOUT the `contract_version` key. Changing any blueprint value
     * changes this digest; changing manifest context outside the section does
     * not.
     *
     * @param array<string, mixed> $normalizedSection the result of {@see self::toArray()}
     */
    private static function computeDigest(array $normalizedSection): string
    {
        $payload = $normalizedSection;
        unset($payload['contract_version']);

        return hash('sha256', CanonicalJson::encode([
            'schema' => self::SCHEMA_ID,
            'contract_version' => self::CONTRACT_VERSION,
            'payload' => $payload,
        ]));
    }
}
