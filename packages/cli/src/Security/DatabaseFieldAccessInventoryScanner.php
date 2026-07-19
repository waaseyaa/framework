<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Security;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\Preflight\FieldAccessLiveInventory;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Security\SignedQueuePayload;

/**
 * Restricted names-only database scanner used by field-access:preflight.
 *
 * It reads schema names, `_data` object keys, and payload type markers. Field
 * and predicate values are discarded in-process and never enter its result.
 *
 * @api
 */
final readonly class DatabaseFieldAccessInventoryScanner
{
    private const array STORAGE_COLUMNS = [
        '_data', 'revision_id', 'revision_default', 'revision_tip',
        'default_revision_id', 'published_revision_id',
    ];

    /** @var array<class-string, true> */
    private array $entityClasses;

    public function __construct(
        private DatabaseInterface $database,
        private EntityTypeManager $entityTypes,
        private ?SignedQueuePayload $queuePayloads = null,
    ) {
        $classes = [];
        foreach ($entityTypes->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if ($class !== '') {
                $classes[$class] = true;
            }
        }
        $this->entityClasses = $classes;
    }

    /** @param array<string, \Waaseyaa\Entity\FieldReadLevel> $artifactLevels */
    public function scan(string $frameworkVersion, array $artifactLevels = []): FieldAccessLiveInventory
    {
        if (!$this->database instanceof DBALDatabase) {
            throw new \RuntimeException('Field-access preflight requires the portable DBAL schema manager.');
        }

        $connection = $this->database->getConnection();
        $schema = $connection->createSchemaManager();
        $tables = $schema->listTableNames();
        sort($tables);
        $schemaShape = [];
        $liveKeys = [];
        $serialized = [];
        $legacyPayloads = [];

        foreach ($tables as $table) {
            $columns = array_keys($schema->listTableColumns($table));
            sort($columns);
            $schemaShape[$table] = $columns;

            if (in_array($table, ['waaseyaa_queue_jobs', 'waaseyaa_failed_jobs'], true)) {
                $this->scanQueueTable($table, $columns, $legacyPayloads, $serialized);
            } elseif (preg_match('/(?:cache|state)/i', $table) === 1) {
                $this->scanSerializedPayloadTable($table, $columns, $serialized);
            }
        }

        $v1Drivers = [];
        foreach ($this->entityTypes->getDefinitions() as $entityType => $definition) {
            foreach ($tables as $table) {
                if ($table !== $entityType && !str_starts_with($table, $entityType . '__')) {
                    continue;
                }
                $this->scanEntityTable($entityType, $table, $schemaShape[$table], $definition->getKeys(), $liveKeys, $legacyPayloads);
            }
        }

        sort($liveKeys);
        sort($serialized);
        sort($legacyPayloads);

        return new FieldAccessLiveInventory(
            frameworkVersion: $frameworkVersion,
            schemaFingerprint: hash('sha256', json_encode($schemaShape, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            liveKeys: array_values(array_unique($liveKeys)),
            artifactLevels: $artifactLevels,
            v1Drivers: array_values(array_unique($v1Drivers)),
            serializedEntities: array_values(array_unique($serialized)),
            legacyPayloads: array_values(array_unique($legacyPayloads)),
        );
    }

    /** @param list<string> $columns @param array<string, string> $keys @param list<string> $liveKeys @param list<string> $blockers */
    private function scanEntityTable(string $entityType, string $table, array $columns, array $keys, array &$liveKeys, array &$blockers): void
    {
        $idColumn = $keys['id'] ?? null;
        $bundleColumn = $keys['bundle'] ?? null;
        $selected = array_values(array_unique(array_filter(
            [$idColumn, $bundleColumn, in_array('_data', $columns, true) ? '_data' : null],
            static fn(mixed $column): bool => is_string($column) && $column !== '',
        )));
        $rows = [];
        if ($selected !== []) {
            $quoted = implode(', ', array_map($this->database->quoteIdentifier(...), $selected));
            $rows = $this->database->query('SELECT ' . $quoted . ' FROM ' . $this->database->quoteIdentifier($table));
        }

        $tableBundle = $this->bundleFromTable($entityType, $table);
        $bundles = [$tableBundle ?? '*'];
        foreach ($rows as $row) {
            if (is_string($bundleColumn) && isset($row[$bundleColumn]) && (string) $row[$bundleColumn] !== '') {
                $bundles[] = (string) $row[$bundleColumn];
            }
            $bundle = is_string($bundleColumn) && isset($row[$bundleColumn]) && (string) $row[$bundleColumn] !== ''
                ? (string) $row[$bundleColumn]
                : ($tableBundle ?? '*');
            if (array_key_exists('_data', $row) && $row['_data'] !== null && $row['_data'] !== '') {
                $rawData = $row['_data'];
                $data = is_string($rawData) ? json_decode($rawData, true) : null;
                $validObject = is_string($rawData)
                    && json_last_error() === JSON_ERROR_NONE
                    && str_starts_with(ltrim($rawData), '{')
                    && is_array($data)
                    && array_all(array_keys($data), static fn(mixed $key): bool => is_string($key) && $key !== '');
                if (!$validObject) {
                    $rowIdentity = is_string($idColumn) && isset($row[$idColumn]) ? (string) $row[$idColumn] : 'unknown';
                    $blockers[] = 'entity-data:' . $table . ':' . $rowIdentity;
                    continue;
                }
                foreach (array_keys($data) as $field) {
                    $liveKeys[] = $entityType . '|' . $bundle . '|' . $field;
                }
            }
        }

        foreach (array_values(array_unique($bundles)) as $bundle) {
            foreach ($columns as $column) {
                if (!in_array($column, self::STORAGE_COLUMNS, true)) {
                    $liveKeys[] = $entityType . '|' . $bundle . '|' . $column;
                }
            }
        }
    }

    private function bundleFromTable(string $entityType, string $table): ?string
    {
        if (!str_starts_with($table, $entityType . '__')) {
            return null;
        }
        $suffix = substr($table, strlen($entityType) + 2);
        if ($suffix === '' || in_array($suffix, ['revision', 'translation', 'translation__revision'], true)) {
            return null;
        }

        return explode('__', $suffix)[0];
    }

    /** @param list<string> $columns @param list<string> $legacy @param list<string> $serialized */
    private function scanQueueTable(string $table, array $columns, array &$legacy, array &$serialized): void
    {
        if (!in_array('payload', $columns, true)) {
            return;
        }
        $id = in_array('id', $columns, true) ? 'id' : null;
        $select = $id === null ? 'payload' : $this->database->quoteIdentifier($id) . ', payload';
        $index = 0;
        foreach ($this->database->query('SELECT ' . $select . ' FROM ' . $this->database->quoteIdentifier($table)) as $row) {
            ++$index;
            $identity = (string) ($id === null ? $index : ($row[$id] ?? $index));
            $payload = (string) ($row['payload'] ?? '');
            $opened = null;
            if ($this->queuePayloads !== null) {
                try {
                    $opened = $this->queuePayloads->open($payload);
                } catch (\RuntimeException) {
                }
            }
            $decoded = is_string($opened)
                ? @unserialize($opened, ['allowed_classes' => [QueueEnvelopeV1::class]])
                : null;
            if (!$decoded instanceof QueueEnvelopeV1) {
                $legacy[] = 'queue:' . $table . ':' . $identity;
            }
            $entityPayload = $decoded instanceof QueueEnvelopeV1 ? $decoded->serializedMessage : ($opened ?? $payload);
            if ($this->containsSerializedEntity($entityPayload)) {
                $serialized[] = 'queue:' . $table . ':' . $identity;
            }
        }
    }

    /** @param list<string> $columns @param list<string> $serialized */
    private function scanSerializedPayloadTable(string $table, array $columns, array &$serialized): void
    {
        $payloadColumns = array_values(array_intersect($columns, ['data', 'value', 'payload']));
        if ($payloadColumns === []) {
            return;
        }
        $quoted = implode(', ', array_map($this->database->quoteIdentifier(...), $payloadColumns));
        $index = 0;
        foreach ($this->database->query('SELECT ' . $quoted . ' FROM ' . $this->database->quoteIdentifier($table)) as $row) {
            ++$index;
            foreach ($payloadColumns as $column) {
                if ($this->containsSerializedEntity((string) ($row[$column] ?? ''))) {
                    $serialized[] = $table . ':' . $index . ':' . $column;
                }
            }
        }
    }

    private function containsSerializedEntity(string $payload, int $depth = 0): bool
    {
        if ($payload === '' || $depth > 3) {
            return false;
        }
        if (preg_match_all('/(?:O|C):\d+:"([^"]+)"/', $payload, $matches) > 0) {
            foreach ($matches[1] as $class) {
                if (isset($this->entityClasses[$class])) {
                    return true;
                }
            }
        }

        $json = json_decode($payload, true);
        if (is_array($json)) {
            $pending = [$json];
            while ($pending !== []) {
                $value = array_pop($pending);
                foreach ($value as $nested) {
                    if (is_array($nested)) {
                        $pending[] = $nested;
                    } elseif (is_string($nested) && $this->containsSerializedEntity($nested, $depth + 1)) {
                        return true;
                    }
                }
            }
        }

        $decoded = base64_decode($payload, true);
        if ($decoded !== false && $decoded !== $payload && base64_encode($decoded) === $payload) {
            return $this->containsSerializedEntity($decoded, $depth + 1);
        }

        return false;
    }
}
