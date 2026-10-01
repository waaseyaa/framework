<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Management;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\ManifestShapeReader;

/** Closed waaseyaa.management v1 companion parser; never executes a binding. @api */
final class ManagementManifestParser
{
    use ManifestShapeReader;

    public function parse(string $json, string $source = '.waaseyaa/management.json'): ManagementManifest
    {
        if (strlen($json) > 1048576) {
            $this->fail($source, 'SITE030_INVALID_MANAGEMENT', '/', 'Management contract exceeds 1 MiB.');
        }
        try {
            $decoded = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            // Reuse the maintained parser to refuse duplicate mapping keys.
            Yaml::parse($json, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (\JsonException|ParseException $exception) {
            $this->fail($source, 'SITE030_INVALID_MANAGEMENT', '/', 'Invalid or ambiguous management JSON.', $exception);
        }
        $root = $this->shape(
            $decoded instanceof \stdClass ? (array) $decoded : $decoded,
            ['schema', 'version', 'application', 'operations'],
            ['schema', 'version', 'application', 'operations'],
            '/',
            $source,
        );
        if ($root['schema'] !== 'waaseyaa.management' || $root['version'] !== 1) {
            $this->fail($source, 'SITE031_UNSUPPORTED_MANAGEMENT_VERSION', '/', 'Expected waaseyaa.management version 1.');
        }
        $application = $this->id($root['application'], '/application', $source);
        $operations = [];
        $canonical = [];
        foreach ($this->list($root['operations'], '/operations', $source, false) as $index => $value) {
            $path = '/operations/' . $index;
            if (!$value instanceof \stdClass) {
                $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected an operation object.');
            }
            $row = (array) $value;
            $state = $this->choice($row['state'] ?? null, ['supported', 'planned', 'unsupported'], $path . '/state', $source);
            $keys = $state === 'supported' ? ['id', 'state', 'contract'] : ['id', 'state', 'reason'];
            $row = $this->shape($row, $keys, $keys, $path, $source);
            $id = $this->operationId($row['id'], $path . '/id', $source);
            $this->assertUniqueId($operations, $id, $path . '/id', $source);
            if ($state === 'supported') {
                if (!$row['contract'] instanceof \stdClass) {
                    $this->fail($source, 'SITE010_INVALID_TYPE', $path . '/contract', 'Expected a contract object.');
                }
                if (property_exists($row['contract'], 'id')) {
                    $this->fail($source, 'SITE001_UNKNOWN_KEY', $path . '/contract/id', 'Operation identity belongs outside the contract.');
                }
                $contract = $this->operation(['id' => $id] + (array) $row['contract'], $source, $path . '/contract');
                $operations[$id] = ['id' => $id, 'state' => $state, 'contract' => $contract];
                $serialized = $contract->toArray();
                unset($serialized['id']);
                $canonical[$id] = ['id' => $id, 'state' => $state, 'contract' => $serialized];
            } else {
                $reason = $this->string($row['reason'], $path . '/reason', $source);
                $operations[$id] = $canonical[$id] = ['id' => $id, 'state' => $state, 'reason' => $reason];
            }
        }
        ksort($operations, SORT_STRING);
        ksort($canonical, SORT_STRING);
        $bytes = CanonicalJson::encode(['schema' => 'waaseyaa.management', 'version' => 1,
            'application' => $application, 'operations' => array_values($canonical)]);

        return new ManagementManifest($application, $operations, $bytes, hash('sha256', $bytes));
    }

    /** Validate descriptors obtained from live adapters using the same structural contract.
     * @param array<string, mixed> $row
     */
    public function operation(array $row, string $source = '<runtime>', string $path = '/operation'): ManagementOperation
    {
        $keys = ['id', 'capability', 'binding', 'input_schema', 'output_schema', 'required_scopes',
            'tenant_scope', 'effects', 'idempotency', 'concurrency', 'dry_run', 'audit', 'approval', 'verification'];
        $row = $this->shape($row, $keys, $keys, $path, $source);
        $id = $this->operationId($row['id'], $path . '/id', $source);
        $capability = $this->id($row['capability'], $path . '/capability', $source);
        $binding = $row['binding'] instanceof \stdClass ? (array) $row['binding'] : $row['binding'];
        $binding = $this->shape($binding, ['transport', 'name'], ['transport', 'name'], $path . '/binding', $source);
        $binding['transport'] = $this->choice($binding['transport'], ['api', 'mcp', 'cli'], $path . '/binding/transport', $source);
        $binding['name'] = $this->string($binding['name'], $path . '/binding/name', $source);
        $row['binding'] = $binding;
        foreach (['input_schema', 'output_schema'] as $key) {
            $schema = $row[$key] instanceof \stdClass ? (array) $row[$key] : $row[$key];
            if (!is_array($schema) || array_is_list($schema) || ($schema['$schema'] ?? null) !== ManagementManifestSchema::DIALECT
                || ($schema['type'] ?? null) !== 'object') {
                $this->fail($source, 'SITE032_INVALID_OPERATION_SCHEMA', $path . '/' . $key, 'Expected a Draft 2020-12 object schema.');
            }
            // Shape and identity only. Compilation belongs to the operation validator.
            $row[$key] = $schema;
        }
        foreach (['required_scopes', 'effects', 'verification'] as $key) {
            $row[$key] = $this->stringList($row[$key], $path . '/' . $key, $source, false);
            sort($row[$key], SORT_STRING);
        }
        foreach ($row['effects'] as $effect) {
            $this->choice($effect, ManagementManifestSchema::EFFECTS, $path . '/effects', $source);
        }
        foreach (ManagementManifestSchema::SEMANTICS as $key => $values) {
            $row[$key] = $this->choice($row[$key], $values, $path . '/' . $key, $source);
        }
        unset($row['id'], $row['capability']);

        return new ManagementOperation($id, $capability, $row);
    }

    private function operationId(mixed $value, string $path, string $source): string
    {
        $id = $this->string($value, $path, $source);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/D', $id) !== 1) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Expected a stable operation identity.');
        }

        return $id;
    }

    /** @param list<string> $values */
    private function choice(mixed $value, array $values, string $path, string $source): string
    {
        $value = $this->string($value, $path, $source);
        if (!in_array($value, $values, true)) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, 'Unsupported management contract value.');
        }

        return $value;
    }
}
