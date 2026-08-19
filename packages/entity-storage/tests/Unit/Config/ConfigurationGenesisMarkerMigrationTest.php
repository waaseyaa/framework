<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * The genesis marker (#2428) is additive on purpose.
 *
 * Widening the activation ledger's `operation` CHECK would have meant rebuilding
 * two triggered, foreign-keyed tables and recreating eight security triggers to
 * record one boolean. These assertions pin the cheaper, safer shape: the column
 * arrives by ALTER TABLE, every pre-existing trigger survives untouched, and the
 * cross-column invariant is enforced by new guards rather than by editing old
 * ones.
 */
final class ConfigurationGenesisMarkerMigrationTest extends TestCase
{
    /** Every trigger defined by 2026_08_15_000004, none of which may be disturbed. */
    private const array PRESERVED_TRIGGERS = [
        'waaseyaa_config_activation_manifest_activation_guard',
        'waaseyaa_config_activation_manifest_delete_guard',
        'waaseyaa_config_activation_manifest_update_guard',
        'waaseyaa_config_entry_contract_delete_guard',
        'waaseyaa_config_entry_contract_update_guard',
        'waaseyaa_config_manifest_replay_activation_insert_guard',
        'waaseyaa_config_manifest_replay_activation_update_guard',
        'waaseyaa_config_manifest_replay_monotonic_guard',
    ];

    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:', 'testing');
    }

    #[Test]
    public function it_adds_the_marker_without_disturbing_any_existing_trigger(): void
    {
        $this->migrate(['000002', '000003', '000004']);
        $before = $this->triggerSql();
        foreach (self::PRESERVED_TRIGGERS as $trigger) {
            self::assertArrayHasKey($trigger, $before, 'Fixture must define the trigger before the migration runs.');
        }

        $this->migrate(['000005']);
        $after = $this->triggerSql();

        foreach (self::PRESERVED_TRIGGERS as $trigger) {
            self::assertArrayHasKey($trigger, $after, sprintf('Trigger %s was dropped.', $trigger));
            self::assertSame(
                $before[$trigger],
                $after[$trigger],
                sprintf('Trigger %s was redefined; the migration must be additive.', $trigger),
            );
        }

        self::assertArrayHasKey('waaseyaa_config_activation_genesis_guard', $after);
        self::assertArrayHasKey('waaseyaa_config_candidate_genesis_guard', $after);
    }

    #[Test]
    public function existing_rows_default_to_not_genesis(): void
    {
        $this->migrate(['000002', '000003', '000004']);
        $this->database->query(
            'INSERT INTO waaseyaa_config_generation_v2 (authority_id, generation_id, schema_version, manifest_hash, created_at) VALUES (?, ?, ?, ?, ?)',
            [str_repeat('a', 64), str_repeat('b', 64), 'config-schema.v1', str_repeat('c', 64), '2026-01-01T00:00:00+00:00'],
        );
        $this->database->query(
            'INSERT INTO waaseyaa_config_candidate (authority_id, activation_request_id, input_hash, generation_id, plan_hash, operation, lifecycle_state, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [str_repeat('a', 64), 'pre-existing', str_repeat('d', 64), str_repeat('b', 64), str_repeat('e', 64), 'activate', 'committed', '2026-01-01T00:00:00+00:00'],
        );

        $this->migrate(['000005']);

        $rows = iterator_to_array($this->database->query(
            'SELECT is_genesis FROM waaseyaa_config_candidate WHERE activation_request_id = ?',
            ['pre-existing'],
        ));
        self::assertSame(0, (int) $rows[0]['is_genesis'], 'A pre-existing activation was never genesis.');
    }

    #[Test]
    public function the_new_guard_refuses_a_genesis_row_that_is_not_the_first_activation(): void
    {
        $this->migrate(['000002', '000003', '000004', '000005']);
        $authority = str_repeat('a', 64);
        $generation = str_repeat('b', 64);
        $this->database->query(
            'INSERT INTO waaseyaa_config_generation_v2 (authority_id, generation_id, schema_version, manifest_hash, created_at) VALUES (?, ?, ?, ?, ?)',
            [$authority, $generation, 'config-schema.v1', str_repeat('c', 64), '2026-01-01T00:00:00+00:00'],
        );
        $this->database->query(
            'INSERT INTO waaseyaa_config_candidate (authority_id, activation_request_id, input_hash, generation_id, plan_hash, operation, lifecycle_state, created_at, is_genesis) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$authority, 'req', str_repeat('d', 64), $generation, str_repeat('e', 64), 'activate', 'committed', '2026-01-01T00:00:00+00:00', 0],
        );

        $this->expectExceptionMessageMatches('/Genesis activation must be the first activation/');
        $this->database->query(
            'INSERT INTO waaseyaa_config_activation_v2 (authority_id, activation_sequence, activation_request_id, generation_id, plan_hash, operation, activated_at, is_genesis) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$authority, 7, 'req', $generation, str_repeat('e', 64), 'activate', '2026-01-01T00:00:00+00:00', 1],
        );
    }

    /** @param list<string> $ids */
    private function migrate(array $ids): void
    {
        $schema = new SchemaBuilder($this->database->getConnection());
        foreach ($ids as $id) {
            $matches = glob(dirname(__DIR__, 3) . '/migrations/*_' . $id . '_*.php') ?: [];
            self::assertCount(1, $matches, sprintf('Exactly one migration must match %s.', $id));
            (require $matches[0])->up($schema);
        }
    }

    /** @return array<string, string> */
    private function triggerSql(): array
    {
        $triggers = [];
        foreach ($this->database->query("SELECT name, sql FROM sqlite_master WHERE type = 'trigger'") as $row) {
            $triggers[(string) $row['name']] = (string) $row['sql'];
        }

        return $triggers;
    }
}
