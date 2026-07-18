<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\EntityStorage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\User;

/**
 * Locks the modern repository → driver save/load path for an entity whose
 * declarative `#[Field]` attributes do not have dedicated columns.
 *
 * Regression for #1375: prior to the fix, `EntityRepository::doSave()`
 * passed raw entity values straight to `SqlStorageDriver::write()`, which
 * built INSERT statements from the value keys. Any `#[Field]` whose column
 * was not materialised by `SqlSchemaHandler::buildTableSpec()` (mail,
 * email_verified, status, created on User) crashed with
 * `SQLSTATE[HY000]: General error: 1 table user has no column named mail`.
 *
 * Existing User integration tests use the legacy `getStorage('user')` path
 * which has its own `splitForStorage()`; the repository → driver path was
 * not exercised against User anywhere in the suite. First caught by the
 * skeleton-smoke CI in #1315.
 */
#[CoversNothing]
final class RepositoryUserRoundTripTest extends TestCase
{
    #[Test]
    public function user_round_trips_through_the_repository_driver_path(): void
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $fieldRegistry = new FieldDefinitionRegistry();

        $entityType = EntityType::fromClass(User::class, group: 'people');
        $schema = new SqlSchemaHandler($entityType, $database, $fieldRegistry);
        $schema->ensureTable();

        $resolver = new SingleConnectionResolver($database);
        $driver = new SqlStorageDriver($resolver, idKey: $entityType->getKeys()['id']);

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $entityType,
            $driver,
            $dispatcher,
            revisionDriver: null,
            database: $database,
        );

        $user = User::make([
            'name' => 'alice',
            'mail' => 'alice@example.com',
            'email_verified' => true,
            'status' => true,
        ]);

        $uid = $repository->save($user);

        self::assertGreaterThan(0, $uid, 'save() must return a positive uid');

        $reloaded = $repository->find((string) $uid);

        self::assertInstanceOf(User::class, $reloaded);
        $internal = new UserInternalFieldReaderFixture();
        $identity = $internal->sessionIdentity($reloaded);
        $verification = $internal->verification($reloaded);
        $credentials = $internal->credentials($reloaded);
        self::assertSame('alice', $identity->name);
        self::assertSame('alice@example.com', $identity->mail);
        self::assertTrue($verification->emailVerified);
        self::assertTrue($credentials->active);
    }
}
