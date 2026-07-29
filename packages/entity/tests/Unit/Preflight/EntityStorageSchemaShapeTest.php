<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Preflight;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Preflight\EntityStorageSchemaShape;

#[CoversClass(EntityStorageSchemaShape::class)]
final class EntityStorageSchemaShapeTest extends TestCase
{
    #[Test]
    public function base_tables_and_double_underscore_subtables_belong_to_entity_storage(): void
    {
        $ids = ['node', 'node_type', 'test_article'];

        self::assertTrue(EntityStorageSchemaShape::belongsToEntityStorage('node', $ids));
        self::assertTrue(EntityStorageSchemaShape::belongsToEntityStorage('node__article', $ids));
        self::assertTrue(EntityStorageSchemaShape::belongsToEntityStorage('node__revision', $ids));
        self::assertTrue(EntityStorageSchemaShape::belongsToEntityStorage('node_type', $ids));
        self::assertTrue(EntityStorageSchemaShape::belongsToEntityStorage('test_article__translation__revision', $ids));
    }

    #[Test]
    public function lazily_created_runtime_tables_do_not_belong_to_entity_storage(): void
    {
        $ids = ['node', 'user', 'media', 'test_article'];

        foreach ([
            'rate_limit_windows',
            'publishing_idempotency',
            'audit_event',
            'privileged_read_ledger',
            'waaseyaa_queue_jobs',
            'waaseyaa_failed_jobs',
            'cache_default',
            'state',
            'search_index',
            'migrations',
            'broadcast_events',
        ] as $table) {
            self::assertFalse(
                EntityStorageSchemaShape::belongsToEntityStorage($table, $ids),
                sprintf('"%s" must not be treated as entity storage.', $table),
            );
        }

        // A single-underscore name is NOT a subtable of a shorter entity id.
        self::assertFalse(EntityStorageSchemaShape::belongsToEntityStorage('node_settings', ['node']));
        self::assertFalse(EntityStorageSchemaShape::belongsToEntityStorage('users', ['user']));
    }

    #[Test]
    public function filter_drops_non_entity_tables_and_canonicalizes_order(): void
    {
        $filtered = EntityStorageSchemaShape::filter([
            'rate_limit_windows' => ['window_key', 'hits'],
            'node__revision' => ['revision_id', 'id', '_data'],
            'node' => ['id', '_data', 'status'],
        ], ['node']);

        self::assertSame([
            'node' => ['_data', 'id', 'status'],
            'node__revision' => ['_data', 'id', 'revision_id'],
        ], $filtered);
    }

    #[Test]
    public function fingerprint_ignores_lazily_added_non_entity_tables(): void
    {
        $ids = ['node'];
        $before = ['node' => ['id', 'status', '_data']];
        $after = $before + [
            'rate_limit_windows' => ['window_key', 'hits', 'reset_at'],
            'publishing_idempotency' => ['idem_key', 'operation', 'request_hash', 'response_json', 'created_at'],
        ];

        self::assertSame(
            EntityStorageSchemaShape::fingerprint(EntityStorageSchemaShape::filter($before, $ids)),
            EntityStorageSchemaShape::fingerprint(EntityStorageSchemaShape::filter($after, $ids)),
        );
    }

    #[Test]
    public function fingerprint_still_changes_when_entity_storage_changes(): void
    {
        $ids = ['node'];
        $base = EntityStorageSchemaShape::fingerprint(
            EntityStorageSchemaShape::filter(['node' => ['id', 'status', '_data']], $ids),
        );

        $columnAdded = EntityStorageSchemaShape::fingerprint(
            EntityStorageSchemaShape::filter(['node' => ['id', 'status', '_data', 'sticky']], $ids),
        );
        $subtableAdded = EntityStorageSchemaShape::fingerprint(
            EntityStorageSchemaShape::filter([
                'node' => ['id', 'status', '_data'],
                'node__article' => ['id', 'kicker'],
            ], $ids),
        );

        self::assertNotSame($base, $columnAdded);
        self::assertNotSame($base, $subtableAdded);
    }

    #[Test]
    public function fingerprint_is_insensitive_to_input_ordering(): void
    {
        $a = EntityStorageSchemaShape::fingerprint([
            'node__revision' => ['revision_id', 'id'],
            'node' => ['status', 'id'],
        ]);
        $b = EntityStorageSchemaShape::fingerprint([
            'node' => ['id', 'status'],
            'node__revision' => ['id', 'revision_id'],
        ]);

        self::assertSame($a, $b);
    }
}
