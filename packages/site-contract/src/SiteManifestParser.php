<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Waaseyaa\SiteContract\Capability\CapabilityDeclaration;
use Waaseyaa\SiteContract\Capability\CapabilityState;
use Waaseyaa\SiteContract\Exception\ManifestViolation;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\Version\ManifestVersionDisposition;
use Waaseyaa\SiteContract\Version\SiteManifestVersionPolicy;

/** @api */
final class SiteManifestParser
{
    public function parse(string $yaml, string $source = '<memory>'): SiteManifest
    {
        try {
            $decoded = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            $this->fail($source, 'SITE000_INVALID_YAML', '/', $exception->getMessage(), $exception);
        }

        $root = $this->shape(
            $decoded,
            ['schema', 'version', 'generator_version', 'application', 'framework', 'content_types', 'capabilities', 'personal_data_stores', 'recipes', 'verification'],
            ['schema', 'version', 'generator_version', 'application', 'framework', 'content_types', 'capabilities', 'personal_data_stores', 'recipes', 'verification'],
            '/',
            $source,
        );
        if (($root['schema'] ?? null) !== 'waaseyaa.site') {
            $this->fail($source, 'SITE014_INVALID_VALUE', '/schema', 'Expected waaseyaa.site.');
        }

        $schemaVersion = $this->integer($root['version'], '/version', $source);
        $disposition = new SiteManifestVersionPolicy()->disposition($schemaVersion);
        if ($disposition === ManifestVersionDisposition::MigrationRequired) {
            $this->fail($source, 'SITE002_MIGRATION_REQUIRED', '/version', 'An explicit manifest migration is required.');
        }
        if ($disposition === ManifestVersionDisposition::UnsupportedFuture) {
            $this->fail($source, 'SITE003_UNSUPPORTED_SCHEMA_VERSION', '/version', 'The manifest schema is newer than this runtime.');
        }

        $generatorVersion = $this->positiveInteger($root['generator_version'], '/generator_version', $source);
        $application = $this->application($root['application'], $source);
        $framework = $this->framework($root['framework'], $source);
        $contentTypes = $this->contentTypes($root['content_types'], $source);
        $capabilities = $this->capabilities($root['capabilities'], $source);
        $personalDataStores = $this->personalDataStores($root['personal_data_stores'], $source);
        $recipes = $this->recipes($root['recipes'], $capabilities, $source);

        $verification = $this->shape($root['verification'], ['command'], ['command'], '/verification', $source);
        $verificationCommand = $this->string($verification['command'], '/verification/command', $source);
        if ($verificationCommand !== 'bin/maintenance/site-verify') {
            $this->fail($source, 'SITE014_INVALID_VALUE', '/verification/command', 'The provider-neutral verification command is fixed.');
        }

        $normalized = [
            'schema' => 'waaseyaa.site',
            'version' => $schemaVersion,
            'generator_version' => $generatorVersion,
            'application' => $application->toArray(),
            'framework' => $framework->toArray(),
            'content_types' => array_map(static fn(ContentTypeDeclaration $item): array => $item->toArray(), array_values($contentTypes)),
            'capabilities' => array_map(static fn(CapabilityDeclaration $item): array => $item->toArray(), array_values($capabilities)),
            'personal_data_stores' => array_map(static fn(PersonalDataStore $item): array => $item->toArray(), array_values($personalDataStores)),
            'recipes' => array_map(static fn(RecipeSelection $item): array => $item->toArray(), array_values($recipes)),
            'verification' => ['command' => $verificationCommand],
        ];
        $canonicalJson = CanonicalJson::encode($normalized);

        return new SiteManifest(
            $schemaVersion,
            $generatorVersion,
            $application,
            $framework,
            $contentTypes,
            $capabilities,
            $personalDataStores,
            $recipes,
            $verificationCommand,
            $canonicalJson,
            hash('sha256', $canonicalJson),
        );
    }

    private function application(mixed $value, string $source): ApplicationIdentity
    {
        $application = $this->shape($value, ['id', 'name', 'canonical_origin'], ['id', 'name', 'canonical_origin'], '/application', $source);
        $origin = $this->shape($application['canonical_origin'], ['config_key'], ['config_key'], '/application/canonical_origin', $source);
        $id = $this->id($application['id'], '/application/id', $source);
        $name = $this->string($application['name'], '/application/name', $source);
        $configKey = $this->string($origin['config_key'], '/application/canonical_origin/config_key', $source);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $configKey) !== 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', '/application/canonical_origin/config_key', 'Expected an environment configuration key, not an origin literal.');
        }

        return new ApplicationIdentity($id, $name, $configKey);
    }

    private function framework(mixed $value, string $source): FrameworkIdentity
    {
        $framework = $this->shape($value, ['revision_policy', 'observed_lock_sha256'], ['revision_policy', 'observed_lock_sha256'], '/framework', $source);
        $policy = $this->string($framework['revision_policy'], '/framework/revision_policy', $source);
        if ($policy !== 'exact-lock') {
            $this->fail($source, 'SITE014_INVALID_VALUE', '/framework/revision_policy', 'Only exact-lock provenance is supported.');
        }

        return new FrameworkIdentity(
            $policy,
            $this->sha256($framework['observed_lock_sha256'], '/framework/observed_lock_sha256', $source),
        );
    }

    /** @return array<string, ContentTypeDeclaration> */
    private function contentTypes(mixed $value, string $source): array
    {
        $rows = $this->list($value, '/content_types', $source, false);
        $result = [];
        $routes = [];
        foreach ($rows as $index => $item) {
            $path = '/content_types/' . $index;
            $row = $this->shape($item, ['id', 'canonical_route'], ['id', 'canonical_route'], $path, $source);
            $id = $this->id($row['id'], $path . '/id', $source);
            $this->assertUniqueId($result, $id, $path . '/id', $source);
            $route = $this->route($row['canonical_route'], $path . '/canonical_route', $source);
            if (isset($routes[$route])) {
                $this->fail($source, 'SITE022_DUPLICATE_ROUTE', $path . '/canonical_route', 'Canonical routes must be unique.');
            }
            $routes[$route] = true;
            $result[$id] = new ContentTypeDeclaration($id, $route);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, CapabilityDeclaration> */
    private function capabilities(mixed $value, string $source): array
    {
        $rows = $this->list($value, '/capabilities', $source, false);
        $result = [];
        foreach ($rows as $index => $item) {
            $path = '/capabilities/' . $index;
            $base = $this->shape(
                $item,
                ['id', 'state', 'package', 'provider', 'configuration_authority', 'public_routes', 'data_classification', 'lifecycle', 'verification', 'note', 'reason'],
                ['id', 'state'],
                $path,
                $source,
                false,
            );
            $id = $this->id($base['id'], $path . '/id', $source);
            $this->assertUniqueId($result, $id, $path . '/id', $source);
            $stateValue = $this->string($base['state'], $path . '/state', $source);
            $state = CapabilityState::tryFrom($stateValue);
            if ($state === null) {
                $this->fail($source, 'SITE014_INVALID_VALUE', $path . '/state', 'Unknown capability state.');
            }

            $result[$id] = match ($state) {
                CapabilityState::Active => $this->activeCapability($base, $path, $source, $id),
                CapabilityState::Planned => $this->plannedCapability($base, $path, $source, $id),
                CapabilityState::NotNeeded => $this->notNeededCapability($base, $path, $source, $id),
            };
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function activeCapability(array $row, string $path, string $source, string $id): CapabilityDeclaration
    {
        $row = $this->shape(
            $row,
            ['id', 'state', 'package', 'provider', 'configuration_authority', 'public_routes', 'data_classification', 'lifecycle', 'verification'],
            ['id', 'state', 'package', 'provider', 'configuration_authority', 'public_routes', 'data_classification', 'lifecycle', 'verification'],
            $path,
            $source,
        );
        $package = $this->string($row['package'], $path . '/package', $source);
        if (preg_match('/^[a-z0-9](?:[_.-]?[a-z0-9]+)*\/[a-z0-9](?:(?:[_.]?|-{0,2})[a-z0-9]+)*$/D', $package) !== 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path . '/package', 'Expected a provider-neutral Composer package identity.');
        }

        return new CapabilityDeclaration(
            $id,
            CapabilityState::Active,
            $package,
            $this->string($row['provider'], $path . '/provider', $source),
            $this->string($row['configuration_authority'], $path . '/configuration_authority', $source),
            $this->routeList($row['public_routes'], $path . '/public_routes', $source),
            $this->string($row['data_classification'], $path . '/data_classification', $source),
            $this->stringList($row['lifecycle'], $path . '/lifecycle', $source, false),
            $this->stringList($row['verification'], $path . '/verification', $source, false),
        );
    }

    /** @param array<string, mixed> $row */
    private function plannedCapability(array $row, string $path, string $source, string $id): CapabilityDeclaration
    {
        $row = $this->shape($row, ['id', 'state', 'note'], ['id', 'state', 'note'], $path, $source);

        return new CapabilityDeclaration(
            $id,
            CapabilityState::Planned,
            note: $this->string($row['note'], $path . '/note', $source),
        );
    }

    /** @param array<string, mixed> $row */
    private function notNeededCapability(array $row, string $path, string $source, string $id): CapabilityDeclaration
    {
        $row = $this->shape($row, ['id', 'state', 'reason'], ['id', 'state', 'reason'], $path, $source);

        return new CapabilityDeclaration(
            $id,
            CapabilityState::NotNeeded,
            reason: $this->string($row['reason'], $path . '/reason', $source),
        );
    }

    /** @return array<string, PersonalDataStore> */
    private function personalDataStores(mixed $value, string $source): array
    {
        $rows = $this->list($value, '/personal_data_stores', $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $path = '/personal_data_stores/' . $index;
            $row = $this->shape(
                $item,
                ['id', 'classification', 'consent_operation', 'retention', 'export_operation', 'deletion_operation'],
                ['id', 'classification', 'consent_operation', 'retention', 'export_operation', 'deletion_operation'],
                $path,
                $source,
            );
            $id = $this->id($row['id'], $path . '/id', $source);
            $this->assertUniqueId($result, $id, $path . '/id', $source);
            $result[$id] = new PersonalDataStore(
                $id,
                $this->string($row['classification'], $path . '/classification', $source),
                $this->string($row['consent_operation'], $path . '/consent_operation', $source),
                $this->string($row['retention'], $path . '/retention', $source),
                $this->string($row['export_operation'], $path . '/export_operation', $source),
                $this->string($row['deletion_operation'], $path . '/deletion_operation', $source),
            );
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param array<string, CapabilityDeclaration> $capabilities
     * @return array<string, RecipeSelection>
     */
    private function recipes(mixed $value, array $capabilities, string $source): array
    {
        $rows = $this->list($value, '/recipes', $source);
        $result = [];
        foreach ($rows as $index => $item) {
            $path = '/recipes/' . $index;
            $row = $this->shape($item, ['id', 'version', 'capability', 'artifact_digest'], ['id', 'version', 'capability', 'artifact_digest'], $path, $source);
            $id = $this->id($row['id'], $path . '/id', $source);
            $this->assertUniqueId($result, $id, $path . '/id', $source);
            $capability = $this->id($row['capability'], $path . '/capability', $source);
            if (($capabilities[$capability]->state ?? null) !== CapabilityState::Active) {
                $this->fail($source, 'SITE031_RECIPE_CAPABILITY_NOT_ACTIVE', $path . '/capability', 'A recipe must reference an active capability.');
            }
            $result[$id] = new RecipeSelection(
                $id,
                $this->positiveInteger($row['version'], $path . '/version', $source),
                $capability,
                $this->sha256($row['artifact_digest'], $path . '/artifact_digest', $source),
            );
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function shape(
        mixed $value,
        array $allowed,
        array $required,
        string $path,
        string $source,
        bool $rejectUnknown = true,
    ): array {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a mapping.');
        }

        if ($rejectUnknown) {
            $unknown = array_values(array_diff(array_keys($value), $allowed));
            sort($unknown, SORT_STRING);
            if ($unknown !== []) {
                $unknownPath = ($path === '/' ? '' : $path) . '/' . $this->pointer($unknown[0]);
                $this->fail($source, 'SITE001_UNKNOWN_KEY', $unknownPath, 'Unknown manifest key.');
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                $requiredPath = ($path === '/' ? '' : $path) . '/' . $this->pointer($key);
                $this->fail($source, 'SITE011_REQUIRED_KEY', $requiredPath, 'Required manifest key is missing.');
            }
        }

        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $path, string $source, bool $allowEmpty = true): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a list.');
        }
        if (!$allowEmpty && $value === []) {
            $this->fail($source, 'SITE012_EMPTY_VALUE', $path, 'At least one entry is required.');
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $path, string $source, bool $allowEmpty = true): array
    {
        $rows = $this->list($value, $path, $source, $allowEmpty);
        $seen = [];
        foreach ($rows as $index => $row) {
            $item = $this->string($row, $path . '/' . $index, $source);
            if (isset($seen[$item])) {
                $this->fail($source, 'SITE021_DUPLICATE_VALUE', $path . '/' . $index, 'List values must be unique.');
            }
            $seen[$item] = true;
            $rows[$index] = $item;
        }

        return $rows;
    }

    /** @return list<string> */
    private function routeList(mixed $value, string $path, string $source): array
    {
        $rows = $this->list($value, $path, $source);
        $seen = [];
        foreach ($rows as $index => $row) {
            $route = $this->route($row, $path . '/' . $index, $source);
            if (isset($seen[$route])) {
                $this->fail($source, 'SITE021_DUPLICATE_VALUE', $path . '/' . $index, 'Route values must be unique.');
            }
            $seen[$route] = true;
            $rows[$index] = $route;
        }

        return $rows;
    }

    private function route(mixed $value, string $path, string $source): string
    {
        $route = $this->string($value, $path, $source);
        $segments = explode('/', $route);
        if (
            preg_match('/^\/(?!\/)[^\x00-\x20\x7F?#\\\\%]*$/D', $route) !== 1
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a local route path without an origin, query, or fragment.');
        }

        return $route;
    }

    private function string(mixed $value, string $path, string $source): string
    {
        if (!is_string($value)) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a string.');
        }
        if ($value === '' || trim($value) !== $value) {
            $this->fail($source, 'SITE012_EMPTY_VALUE', $path, 'Expected a non-empty, trimmed string.');
        }

        return $value;
    }

    private function id(mixed $value, string $path, string $source): string
    {
        $id = $this->string($value, $path, $source);
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $id) !== 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a stable lowercase identity.');
        }

        return $id;
    }

    private function integer(mixed $value, string $path, string $source): int
    {
        if (!is_int($value)) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected an integer.');
        }

        return $value;
    }

    private function positiveInteger(mixed $value, string $path, string $source): int
    {
        $integer = $this->integer($value, $path, $source);
        if ($integer < 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a positive integer.');
        }

        return $integer;
    }

    private function sha256(mixed $value, string $path, string $source): string
    {
        $digest = $this->string($value, $path, $source);
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a lowercase SHA-256 digest.');
        }

        return $digest;
    }

    /** @param array<string, mixed> $items */
    private function assertUniqueId(array $items, string $id, string $path, string $source): void
    {
        if (array_key_exists($id, $items)) {
            $this->fail($source, 'SITE020_DUPLICATE_ID', $path, 'Identities must be unique within their collection.');
        }
    }

    private function pointer(string|int $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], (string) $key);
    }

    private function fail(
        string $source,
        string $code,
        string $path,
        string $message,
        ?\Throwable $previous = null,
    ): never {
        throw new SiteManifestValidationException(
            $source,
            [new ManifestViolation($code, $path, $message)],
            $previous,
        );
    }
}
