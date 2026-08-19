<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\StorageInterface;
use Waaseyaa\Database\DatabaseInterface;

/** Read-only StorageInterface view pinned to one immutable generation. */
final class DatabaseActiveConfigurationStorage implements StorageInterface
{
    /** @var array<string, self> */
    private array $collections = [];

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ConfigurationAuthorityContext $context,
        private readonly string $collection = '',
    ) {
        // #2426: deliberately no active-generation assertion here. Construction
        // must be side-effect free so a fresh install can boot far enough to
        // apply and activate its configuration. Every read and mutation path
        // below still calls requireActiveGenerationId(), so the fail-closed
        // guarantee lives where a caller actually touches configuration.
    }

    public function exists(string $name): bool
    {
        return $this->read($name) !== false;
    }

    public function read(string $name): array|false
    {
        $table = $this->entryTable();
        foreach ($this->database->query(
            "SELECT fields_json FROM {$table} WHERE authority_id = ? AND generation_id = ? AND config_name = ?",
            [$this->context->authorityId, $this->context->requireActiveGenerationId(), $this->qualified($name)],
        ) as $row) {
            $decoded = json_decode((string) $row['fields_json'], true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        }

        return false;
    }

    public function readMultiple(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $value = $this->read((string) $name);
            if ($value !== false) {
                $result[(string) $name] = $value;
            }
        }

        return $result;
    }

    public function write(string $name, array $data): bool
    {
        throw $this->mutationUnavailable();
    }

    public function delete(string $name): bool
    {
        throw $this->mutationUnavailable();
    }

    public function rename(string $name, string $newName): bool
    {
        throw $this->mutationUnavailable();
    }

    public function listAll(string $prefix = ''): array
    {
        $table = $this->entryTable();
        $collectionPrefix = $this->collection === '' ? '' : $this->collection . '::';
        $rows = $this->database->query(
            "SELECT config_name FROM {$table} WHERE authority_id = ? AND generation_id = ? ORDER BY config_name",
            [$this->context->authorityId, $this->context->requireActiveGenerationId()],
        );
        $names = [];
        foreach ($rows as $row) {
            $name = (string) $row['config_name'];
            if (!str_starts_with($name, $collectionPrefix)) {
                continue;
            }
            $name = substr($name, strlen($collectionPrefix));
            if ($this->collection === '' && str_contains($name, '::')) {
                continue;
            }
            if ($prefix === '' || str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function deleteAll(string $prefix = ''): bool
    {
        throw $this->mutationUnavailable();
    }

    public function createCollection(string $collection): static
    {
        if (!isset($this->collections[$collection])) {
            $this->collections[$collection] = new self($this->database, $this->context, $collection);
        }

        return $this->collections[$collection];
    }

    public function getCollectionName(): string
    {
        return $this->collection;
    }

    public function getAllCollectionNames(): array
    {
        $table = $this->entryTable();
        $collections = [];
        foreach ($this->database->query(
            "SELECT config_name FROM {$table} WHERE authority_id = ? AND generation_id = ?",
            [$this->context->authorityId, $this->context->requireActiveGenerationId()],
        ) as $row) {
            $name = (string) $row['config_name'];
            if (str_contains($name, '::')) {
                $collections[] = strstr($name, '::', true);
            }
        }

        $collections = array_values(array_unique($collections));
        sort($collections);

        return $collections;
    }

    private function qualified(string $name): string
    {
        return $this->collection === '' ? $name : $this->collection . '::' . $name;
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

    private function mutationUnavailable(): ConfigurationAuthorityUnavailableException
    {
        return new ConfigurationAuthorityUnavailableException(
            'Active configuration generations are immutable; CFG-02 transactional activation is not bound.',
        );
    }
}
