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
    private readonly DatabaseActiveConfigurationStorage $storage;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ConfigurationAuthorityContext $context,
    ) {
        $this->storage = new DatabaseActiveConfigurationStorage($database, $context);
    }

    public function authorityContext(): ConfigurationAuthorityContext
    {
        return $this->context;
    }

    public function activeStorage(): StorageInterface
    {
        return $this->storage;
    }

    public function iterate(): iterable
    {
        $rows = $this->database->query(
            'SELECT entity_type, entity_id, uuid, dependencies_json, langcode, fields_json '
            . 'FROM waaseyaa_config_entry WHERE authority_id = ? AND generation_id = ? ORDER BY entity_type, entity_id',
            [$this->context->authorityId, $this->context->requireActiveGenerationId()],
        );
        foreach ($rows as $row) {
            $dependencies = json_decode((string) $row['dependencies_json'], true, flags: JSON_THROW_ON_ERROR);
            $fields = json_decode((string) $row['fields_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($dependencies) || !is_array($fields)) {
                throw new \UnexpectedValueException('Active configuration entry JSON has an invalid shape.');
            }
            ksort($fields, SORT_STRING);

            yield new ConfigSyncFile(
                entityType: (string) $row['entity_type'],
                entityId: (string) $row['entity_id'],
                uuid: (string) $row['uuid'],
                dependencies: array_values(array_filter($dependencies, 'is_string')),
                langcode: (string) $row['langcode'],
                fields: $fields,
            );
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
}
