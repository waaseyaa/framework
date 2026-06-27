<?php

declare(strict_types=1);

namespace Waaseyaa\State;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DatabaseInterface;

/**
 * @api
 */
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
            // Trust boundary (D-12): state values are this application's own
            // serialized payloads from a server-controlled table and are `mixed`
            // (objects allowed — see SqlStateTest::testSerializationOfObject), so
            // `allowed_classes => false` cannot be used. Deferred hardening (HMAC
            // integrity signing) is tracked in docs/specs/infrastructure.md
            // "Stored-payload unserialize() trust boundary (D-12)".
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
                // Trust boundary (D-12): see get() — server-controlled `mixed`
                // state payload; `allowed_classes => false` is not viable.
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

        // Fast path: update an existing key.
        $affected = $this->database->update('state')
            ->fields(['value' => $serialized])
            ->condition('name', $key)
            ->execute();

        if ($affected === 0) {
            try {
                $this->database->insert('state')
                    ->fields(['name', 'value'])
                    ->values(['name' => $key, 'value' => $serialized])
                    ->execute();
            } catch (UniqueConstraintViolationException) {
                // Lost the race: another writer inserted this key between our UPDATE
                // (which saw 0 rows) and our INSERT. Re-apply our value as an UPDATE
                // so set() is last-writer-wins and never throws on a new-key race.
                $this->database->update('state')
                    ->fields(['value' => $serialized])
                    ->condition('name', $key)
                    ->execute();
            }
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
