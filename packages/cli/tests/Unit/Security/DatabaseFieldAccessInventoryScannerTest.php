<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Security;

require_once __DIR__ . '/../../Fixtures/AppProfileEntity.php';

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendInterface;
use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsInterface;
use Waaseyaa\EntityStorage\Query\EntityQuery;
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
        $entityEnvelope = QueueEnvelopeV1::forSystem(
            serialize(new \App\ProfileEntity(['id' => 1])),
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
            6 => $signer->seal(serialize(new \App\ProfileEntity(['id' => 2]))),
        ];
        foreach ($rows as $id => $payload) {
            $database->query('INSERT INTO waaseyaa_queue_jobs (id, payload) VALUES (?, ?)', [$id, $payload]);
        }
        $database->query('INSERT INTO waaseyaa_failed_jobs (id, payload) VALUES (?, ?)', [5, $signer->seal(serialize($current))]);
        $database->query('INSERT INTO app_cache (id, data) VALUES (?, ?)', [1, json_encode([
            'wrapped' => base64_encode(serialize(new \App\ProfileEntity(['id' => 3]))),
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

    public function test_retained_v1_field_storage_backends_are_exact_sorted_preflight_blockers(): void
    {
        $database = DBALDatabase::createSqlite();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: new FieldDefinitionRegistry());
        DatabasePreflightBackendProvider::$backends = [
            new DatabasePreflightLegacyBackend('zeta'),
            new DatabasePreflightLegacyBackend('alpha'),
        ];
        $registrar = new BackendRegistrar([DatabasePreflightBackendProvider::class]);
        $registrar->build();

        $inventory = new DatabaseFieldAccessInventoryScanner(
            $database,
            $manager,
            backendRegistrar: $registrar,
        )->scan('candidate-1');

        self::assertSame([
            DatabasePreflightBackendProvider::class . ':alpha:' . DatabasePreflightLegacyBackend::class,
            DatabasePreflightBackendProvider::class . ':zeta:' . DatabasePreflightLegacyBackend::class,
        ], $inventory->v1Drivers);
    }
}

final class DatabasePreflightProfile extends EntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'profile', ['id' => 'pid', 'uuid' => 'uuid', 'bundle' => 'type', 'label' => 'name']);
    }
}

final class DatabasePreflightBackendProvider implements HasFieldStorageBackendsInterface
{
    /** @var list<FieldStorageBackendInterface> */
    public static array $backends = [];

    public function fieldStorageBackends(): array
    {
        return self::$backends;
    }
}

final class DatabasePreflightLegacyBackend implements FieldStorageBackendInterface
{
    public function __construct(private readonly string $backendId) {}
    public function id(): string
    {
        return $this->backendId;
    }
    public function read(EntityInterface $entity, FieldDefinition $field): mixed
    {
        return null;
    }
    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void {}
    public function delete(EntityInterface $entity): void {}
    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return false;
    }
}
