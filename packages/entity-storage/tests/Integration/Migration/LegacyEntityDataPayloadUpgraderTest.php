<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Migration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Migration\LegacyEntityDataPayloadUpgrader;

final class LegacyEntityDataPayloadUpgraderTest extends TestCase
{
    #[Test]
    public function it_upgrades_the_real_sfn_migrated_shape_and_only_exact_empty_json_lists(): void
    {
        $database = DBALDatabase::createSqlite();
        $fixture = dirname(__DIR__, 2) . '/Fixtures/sfn-migrated-config-empty-list-data.sql';
        foreach (file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $statement) {
            $database->query($statement);
        }
        $database->query("INSERT INTO media_type (id, label, _data) VALUES ('object', 'Object', '{}')");
        $database->query("INSERT INTO media_type (id, label, _data) VALUES ('nonempty-list', 'List', '[{\"field\":\"value\"}]')");
        $database->query(
            'INSERT INTO media_type (id, label, _data) VALUES (?, ?, ?)',
            ['scalar', 'Scalar', '"value"'],
        );
        $database->query("INSERT INTO media_type (id, label, _data) VALUES ('malformed', 'Malformed', '{broken')");
        $database->query("INSERT INTO media_type (id, label, _data) VALUES ('whitespace-list', 'Whitespace', '  [ ]  ')");

        $manager = new EntityTypeManager(new EventDispatcher());
        foreach (['media_type', 'menu'] as $entityType) {
            $manager->registerEntityType(new EntityType(
                id: $entityType,
                label: $entityType,
                class: LegacyPayloadFixtureEntity::class,
                keys: ['id' => 'id'],
            ));
        }

        $upgrader = new LegacyEntityDataPayloadUpgrader($database, $manager);
        $first = $upgrader->upgrade();
        $second = $upgrader->upgrade();

        self::assertSame(10, $first->scannedRows);
        self::assertSame(6, $first->changedRows);
        self::assertSame(['media_type' => 5, 'menu' => 1], $first->changedByTable);
        self::assertSame(10, $second->scannedRows);
        self::assertSame(0, $second->changedRows);
        self::assertSame([], $second->changedByTable);

        $payloads = [];
        foreach ($database->query('SELECT id, _data FROM media_type ORDER BY id') as $row) {
            $payloads[(string) $row['id']] = $row['_data'];
        }
        self::assertSame('{}', $payloads['document']);
        self::assertSame('{}', $payloads['whitespace-list']);
        self::assertSame('{}', $payloads['object']);
        self::assertSame('[{"field":"value"}]', $payloads['nonempty-list']);
        self::assertSame('"value"', $payloads['scalar']);
        self::assertSame('{broken', $payloads['malformed']);
    }
}

final class LegacyPayloadFixtureEntity extends EntityBase
{
}
