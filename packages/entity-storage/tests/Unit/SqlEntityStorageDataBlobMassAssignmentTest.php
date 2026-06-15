<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Pins the audit C-11 disposition: the entity write boundary (`splitForStorage`)
 * persists every value key it receives, including keys with NO registered
 * `FieldDefinition` — they land verbatim in the open `_data` JSON blob.
 *
 * This is INTENTIONAL (entity-system.md). The framework's own security-critical
 * User fields — `pass` (the password hash), `roles`, `permissions` — are stored
 * as unregistered `_data` keys. A registered-field allowlist at this boundary
 * (the audit's recommendation, reclassified as a regression) would silently DROP
 * them on save, breaking authentication and authorization. `splitForStorage` is
 * identity-blind, so it cannot be the mass-assignment control anyway; that lives
 * one layer up at `FieldAccessPolicyInterface::fieldAccess()` (the B-1 fix). If
 * anyone hardens the write boundary into dropping/filtering unregistered keys,
 * the assertions below fail loudly.
 */
#[CoversClass(SqlEntityStorage::class)]
final class SqlEntityStorageDataBlobMassAssignmentTest extends TestCase
{
    private SqlEntityStorage $storage;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        (new SqlSchemaHandler($entityType, $database))->ensureTable();
        $this->storage = new SqlEntityStorage($entityType, $database, new EventDispatcher());
    }

    #[Test]
    public function unregisteredCredentialShapedKeysPersistVerbatimThroughDataBlob(): void
    {
        $hash = '$argon2id$v=19$m=65536,t=4,p=1$abcdefghijklmnop$zzzzzzzzzzzzzzzzzzzzzzzzzzzz';

        $entity = $this->storage->create([
            'uuid' => 'uuid-mallory',
            'title' => 'Mallory',
            // None of these are registered FieldDefinitions / schema columns —
            // they are exactly the credential-shaped keys User stores in _data.
            'pass' => $hash,
            'roles' => ['administrator'],
            'permissions' => ['administer users'],
            'extra' => ['nested' => ['deep' => true]],
        ]);
        $this->storage->save($entity);

        $loaded = $this->storage->load($entity->id());
        self::assertInstanceOf(EntityInterface::class, $loaded);

        // A write-boundary allowlist would have dropped all four on save.
        self::assertSame($hash, $loaded->get('pass'), 'unregistered credential key `pass` must round-trip through _data');
        self::assertSame(['administrator'], $loaded->get('roles'), '`roles` must round-trip through _data');
        self::assertSame(['administer users'], $loaded->get('permissions'), '`permissions` must round-trip through _data');
        self::assertSame(['nested' => ['deep' => true]], $loaded->get('extra'), 'arbitrary nested _data must round-trip');
    }
}
