<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\StorageInterface;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Database\DatabaseInterface;

final class DatabaseActiveConfigurationBridge implements ActiveConfigurationBridgeInterface
{
    private ?DatabaseActiveConfigurationStorage $storage = null;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ConfigurationAuthorityContext $context,
    ) {}

    public function authorityContext(): ConfigurationAuthorityContext
    {
        return $this->context;
    }

    public function activeStorage(): StorageInterface
    {
        // #2426: built on demand. Access-policy discovery resolves this bridge
        // during AbstractKernel::boot(); eager construction made a fresh
        // install unbootable before it could activate a generation.
        return $this->storage ??= new DatabaseActiveConfigurationStorage($this->database, $this->context);
    }

    public function iterate(): iterable
    {
        $table = $this->entryTable();
        $hasContracts = $table === 'waaseyaa_config_entry_v2'
            && $this->database->schema()->tableExists('waaseyaa_config_entry_contract');
        $select = 'SELECT e.entity_type, e.entity_id, e.uuid, e.dependencies_json, e.langcode, e.fields_json';
        $join = '';
        if ($hasContracts) {
            $select .= ', c.format, c.schema_id, c.schema_version, c.schema_hash, c.owner_package, c.owner_config_contract_version';
            $join = ' LEFT JOIN waaseyaa_config_entry_contract c ON c.authority_id = e.authority_id '
                . 'AND c.generation_id = e.generation_id AND c.config_name = e.config_name';
        }
        $rows = $this->database->query(
            $select . " FROM {$table} e" . $join
            . ' WHERE e.authority_id = ? AND e.generation_id = ? ORDER BY e.entity_type, e.entity_id',
            [$this->context->authorityId, $this->context->requireActiveGenerationId()],
        );
        foreach ($rows as $row) {
            $dependencies = json_decode((string) $row['dependencies_json'], true, flags: JSON_THROW_ON_ERROR);
            $fields = json_decode((string) $row['fields_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($dependencies) || !is_array($fields)) {
                throw new \UnexpectedValueException('Active configuration entry JSON has an invalid shape.');
            }
            ksort($fields, SORT_STRING);

            $arguments = [
                'entityType' => (string) $row['entity_type'],
                'entityId' => (string) $row['entity_id'],
                'uuid' => (string) $row['uuid'],
                'dependencies' => array_values(array_filter($dependencies, 'is_string')),
                'langcode' => (string) $row['langcode'],
                'fields' => $fields,
            ];
            if (($row['format'] ?? null) === ConfigSyncFile::FORMAT_V1) {
                yield ConfigSyncFile::writable(
                    ...$arguments,
                    schemaId: (string) $row['schema_id'],
                    schemaVersion: (int) $row['schema_version'],
                    schemaHash: (string) $row['schema_hash'],
                    ownerPackage: (string) $row['owner_package'],
                    ownerConfigContractVersion: (int) $row['owner_config_contract_version'],
                );
            } else {
                yield ConfigSyncFile::legacyReadable(...$arguments);
            }
        }
    }

    public function apply(ConfigSyncFile $file): string
    {
        throw $this->mutationUnavailable();
    }

    public function delete(string $ref): void
    {
        throw $this->mutationUnavailable();
    }

    private function mutationUnavailable(): ConfigurationAuthorityUnavailableException
    {
        return new ConfigurationAuthorityUnavailableException(
            'Configuration mutation is unavailable until CFG-02 activation and CFG-03 validation gates are bound.',
        );
    }

    private function entryTable(): string
    {
        if ($this->database->schema()->tableExists('waaseyaa_config_generation_v2')) {
            foreach ($this->database->query(
                'SELECT 1 FROM waaseyaa_config_generation_v2 WHERE authority_id = ? AND generation_id = ?',
                [$this->context->authorityId, $this->context->requireActiveGenerationId()],
            ) as $_row) {
                return 'waaseyaa_config_entry_v2';
            }
        }

        return 'waaseyaa_config_entry';
    }
}
