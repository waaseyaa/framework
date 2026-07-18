<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Security;

require_once __DIR__ . '/../../Fixtures/AppProfileEntity.php';

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Envelope\QueueSystemReason;
use Waaseyaa\Queue\Security\SignedQueuePayload;

final class DatabaseFieldAccessInventoryScannerTest extends TestCase
{
    public function test_live_schema_data_keys_and_persistent_payloads_are_inventoried_by_name_only(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->query('CREATE TABLE profile (pid INTEGER PRIMARY KEY, uuid TEXT, type TEXT, name TEXT, _data TEXT)');
        $database->query("INSERT INTO profile (pid, uuid, type, name, _data) VALUES (1, 'u1', 'member', 'n', '{\"bio\":\"private value\",\"legacy_key\":\"not emitted\"}')");
        $database->query('CREATE TABLE waaseyaa_queue_jobs (id INTEGER PRIMARY KEY, payload TEXT)');
        $database->query("INSERT INTO waaseyaa_queue_jobs (id, payload) VALUES (9, 'legacy-payload')");

        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
        $manager->registerEntityType(new EntityType(
            id: 'profile',
            label: 'Profile',
            class: DatabasePreflightProfile::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'bundle' => 'type', 'label' => 'name'],
            _fieldDefinitions: [
                'name' => new FieldDefinition('name', 'string', targetEntityTypeId: 'profile', read: FieldReadLevel::Protected),
            ],
        ));
        $registry->registerBundleFields('profile', 'member', [
            new FieldDefinition('bio', 'text', targetEntityTypeId: 'profile', targetBundle: 'member', read: FieldReadLevel::Protected),
        ]);

        $inventory = new DatabaseFieldAccessInventoryScanner($database, $manager)->scan('candidate-1');

        self::assertContains('profile|member|bio', $inventory->liveKeys);
        self::assertContains('profile|member|legacy_key', $inventory->liveKeys);
        self::assertContains('queue:waaseyaa_queue_jobs:9', $inventory->legacyPayloads);
        self::assertStringNotContainsString('private value', json_encode($inventory, JSON_THROW_ON_ERROR));
        self::assertNotSame('', $inventory->schemaFingerprint);
    }

    public function test_signed_current_legacy_tampered_failed_and_entity_payloads_are_distinguished(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->query('CREATE TABLE waaseyaa_queue_jobs (id INTEGER PRIMARY KEY, payload TEXT)');
        $database->query('CREATE TABLE waaseyaa_failed_jobs (id INTEGER PRIMARY KEY, payload TEXT)');
        $database->query('CREATE TABLE app_cache (id INTEGER PRIMARY KEY, data TEXT)');
        $signer = new SignedQueuePayload(str_repeat('k', 32));
        $current = QueueEnvelopeV1::forSystem(
            serialize(new \stdClass()),
            QueueSystemReason::SystemJob,
            'preflight-test',
            null,
            null,
            'correlation-1',
        );
        // Authenticated representation of a row written before sealed entities
        // forbade serialization. Do not construct or serialize a live WP4 entity.
        $legacySerializedEntity = 'O:17:"App\\ProfileEntity":0:{}';
        $entityEnvelope = QueueEnvelopeV1::forSystem(
            $legacySerializedEntity,
            QueueSystemReason::SystemJob,
            'preflight-test',
            null,
            null,
            'correlation-2',
        );
        $rows = [
            1 => $signer->seal(serialize($current)),
            2 => $signer->seal(serialize(new \stdClass())),
            3 => $signer->seal(serialize($current)) . 'tampered',
            4 => $signer->seal(serialize($entityEnvelope)),
            6 => $signer->seal($legacySerializedEntity),
        ];
        foreach ($rows as $id => $payload) {
            $database->query('INSERT INTO waaseyaa_queue_jobs (id, payload) VALUES (?, ?)', [$id, $payload]);
        }
        $database->query('INSERT INTO waaseyaa_failed_jobs (id, payload) VALUES (?, ?)', [5, $signer->seal(serialize($current))]);
        $database->query('INSERT INTO app_cache (id, data) VALUES (?, ?)', [1, json_encode([
            'wrapped' => base64_encode($legacySerializedEntity),
        ], JSON_THROW_ON_ERROR)]);

        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: new FieldDefinitionRegistry());
        $manager->registerEntityType(new EntityType(
            id: 'app_profile',
            label: 'Application profile',
            class: \App\ProfileEntity::class,
        ));
        $inventory = new DatabaseFieldAccessInventoryScanner($database, $manager, $signer)->scan('candidate-1');

        self::assertNotContains('queue:waaseyaa_queue_jobs:1', $inventory->legacyPayloads);
        self::assertContains('queue:waaseyaa_queue_jobs:2', $inventory->legacyPayloads);
        self::assertContains('queue:waaseyaa_queue_jobs:3', $inventory->legacyPayloads);
        self::assertNotContains('queue:waaseyaa_failed_jobs:5', $inventory->legacyPayloads);
        self::assertContains('queue:waaseyaa_queue_jobs:4', $inventory->serializedEntities);
        self::assertContains('queue:waaseyaa_queue_jobs:6', $inventory->legacyPayloads);
        self::assertContains('queue:waaseyaa_queue_jobs:6', $inventory->serializedEntities);
        self::assertContains('app_cache:1:data', $inventory->serializedEntities);
    }

    public function test_unreadable_entity_data_rows_block_readiness_without_retaining_values(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->query('CREATE TABLE profile (pid INTEGER PRIMARY KEY, type TEXT, _data TEXT)');
        $database->query('INSERT INTO profile (pid, type, _data) VALUES (?, ?, ?)', [1, 'member', '{invalid']);
        $database->query('INSERT INTO profile (pid, type, _data) VALUES (?, ?, ?)', [2, 'member', '"scalar"']);
        $database->query('INSERT INTO profile (pid, type, _data) VALUES (?, ?, ?)', [3, 'member', '["unexpected-list"]']);

        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: new FieldDefinitionRegistry());
        $manager->registerEntityType(new EntityType(
            id: 'profile',
            label: 'Profile',
            class: DatabasePreflightProfile::class,
            keys: ['id' => 'pid', 'bundle' => 'type'],
        ));
        $inventory = new DatabaseFieldAccessInventoryScanner($database, $manager)->scan('candidate-1');

        self::assertSame([
            'entity-data:profile:1',
            'entity-data:profile:2',
            'entity-data:profile:3',
        ], $inventory->legacyPayloads);
    }

    public function test_v1_field_storage_inventory_is_empty_after_v2_activation(): void
    {
        $database = DBALDatabase::createSqlite();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: new FieldDefinitionRegistry());
        $inventory = new DatabaseFieldAccessInventoryScanner($database, $manager)->scan('candidate-1');

        self::assertSame([], $inventory->v1Drivers);
    }
}

final class DatabasePreflightProfile extends EntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'profile', ['id' => 'pid', 'uuid' => 'uuid', 'bundle' => 'type', 'label' => 'name']);
    }
}
