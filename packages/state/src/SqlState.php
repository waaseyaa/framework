<?php

declare(strict_types=1);

namespace Aurora\State;

use Aurora\Database\DatabaseInterface;

final class SqlState implements StateInterface
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /** @var bool Whether the state table has been verified/created. */
    private bool $tableEnsured = false;

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $this->ensureTable();

        $result = $this->database->select('state', 's')
            ->fields('s', ['value'])
            ->condition('s.name', $key)
            ->execute();

        foreach ($result as $row) {
            $value = unserialize($row['value']);
            $this->cache[$key] = $value;
            return $value;
        }

        return $default;
    }

    public function getMultiple(array $keys): array
    {
        $values = [];
        $keysToLoad = [];

        // Check cache first.
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->cache)) {
                $values[$key] = $this->cache[$key];
            } else {
                $keysToLoad[] = $key;
            }
        }

        if (!empty($keysToLoad)) {
            $this->ensureTable();

            $result = $this->database->select('state', 's')
                ->fields('s', ['name', 'value'])
                ->condition('s.name', $keysToLoad, 'IN')
                ->execute();

            foreach ($result as $row) {
                $value = unserialize($row['value']);
                $this->cache[$row['name']] = $value;
                $values[$row['name']] = $value;
            }
        }

        return $values;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureTable();

        $serialized = serialize($value);

        // Try to update first, if no rows affected then insert.
        $affected = $this->database->update('state')
            ->fields(['value' => $serialized])
            ->condition('name', $key)
            ->execute();

        if ($affected === 0) {
            $this->database->insert('state')
                ->fields(['name', 'value'])
                ->values(['name' => $key, 'value' => $serialized])
                ->execute();
        }

        $this->cache[$key] = $value;
    }

    public function setMultiple(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function delete(string $key): void
    {
        $this->ensureTable();

        $this->database->delete('state')
            ->condition('name', $key)
            ->execute();

        unset($this->cache[$key]);
    }

    public function deleteMultiple(array $keys): void
    {
        $this->ensureTable();

        foreach ($keys as $key) {
            $this->database->delete('state')
                ->condition('name', $key)
                ->execute();
        }

        foreach ($keys as $key) {
            unset($this->cache[$key]);
        }
    }

    /**
     * Creates the state table if it does not already exist.
     */
    public function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $schema = $this->database->schema();

        if (!$schema->tableExists('state')) {
            $schema->createTable('state', [
                'fields' => [
                    'name' => [
                        'type' => 'varchar',
                        'not null' => true,
                    ],
                    'value' => [
                        'type' => 'text',
                        'not null' => false,
                    ],
                ],
                'primary key' => ['name'],
            ]);
        }

        $this->tableEnsured = true;
    }
}
