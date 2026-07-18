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
    /** @var bool Whether the state table has been verified/created. */
    private bool $tableEnsured = false;
    private readonly SignedStatePayload $payloadSigner;
    private readonly ProjectionDeprecationDiagnostic $projectionDiagnostic;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        string $hmacKey,
        ?ProjectionDeprecationDiagnostic $projectionDiagnostic = null,
    ) {
        $this->payloadSigner = new SignedStatePayload($hmacKey);
        $this->projectionDiagnostic = $projectionDiagnostic ?? ProjectionDeprecationDiagnostic::forEntityPayloads(
            static function (): void {},
            EntityPayloadBoundaryConfig::enforced(),
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureTable();

        $result = $this->database->select('state', 's')
            ->fields('s', ['value'])
            ->condition('s.name', $key)
            ->execute();

        foreach ($result as $row) {
            return unserialize($this->payloadSigner->open((string) $row['value']));
        }

        return $default;
    }

    public function getMultiple(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $values = [];
        $this->ensureTable();

        $result = $this->database->select('state', 's')
            ->fields('s', ['name', 'value'])
            ->condition('s.name', $keys, 'IN')
            ->execute();

        foreach ($result as $row) {
            $values[$row['name']] = unserialize($this->payloadSigner->open((string) $row['value']));
        }

        return $values;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureTable();

        $value = $this->projectionDiagnostic->inspect($key, $value);
        $serialized = $this->payloadSigner->seal(serialize($value));

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
    }

    public function deleteMultiple(array $keys): void
    {
        $this->ensureTable();

        foreach ($keys as $key) {
            $this->database->delete('state')
                ->condition('name', $key)
                ->execute();
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
