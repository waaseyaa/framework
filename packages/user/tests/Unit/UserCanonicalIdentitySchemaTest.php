<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityValueContainer;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Hydration\EntityInstantiator;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\User\User;

#[CoversClass(User::class)]
final class UserCanonicalIdentitySchemaTest extends TestCase
{
    #[Test]
    public function ordinaryRepositoryCreationCannotRaceCaseVariantName(): void
    {
        [$repository] = self::repository();
        $repository->save($repository->create([
            'name' => 'Community.Owner',
            'mail' => 'Owner@Example.TEST',
        ]), validate: false);

        $duplicate = $repository->create([
            'name' => 'community.owner',
            'mail' => 'different@example.test',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save($duplicate, validate: false);
    }

    #[Test]
    public function ordinaryRepositoryCreationCannotRaceCaseVariantMail(): void
    {
        [$repository] = self::repository();
        $repository->save($repository->create([
            'name' => 'first',
            'mail' => 'Owner@Example.TEST',
        ]), validate: false);

        $duplicate = $repository->create([
            'name' => 'second',
            'mail' => 'owner@example.test',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save($duplicate, validate: false);
    }

    #[Test]
    public function ordinaryRenameCannotEnterAnotherAccountsCanonicalNamespace(): void
    {
        [$repository] = self::repository();
        $first = $repository->create([
            'name' => 'first',
            'mail' => 'first@example.test',
        ]);
        $second = $repository->create([
            'name' => 'second',
            'mail' => 'second@example.test',
        ]);
        $repository->save($first, validate: false);
        $repository->save($second, validate: false);
        $second->setName('FIRST');

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save($second, validate: false);
    }

    #[Test]
    public function repositoryCreationOwnsCanonicalBindingsAndOverwritesForgedValues(): void
    {
        [$repository, $database] = self::repository();
        $user = $repository->create([
            'name' => 'Community.Owner',
            'mail' => 'Owner@Example.TEST',
            'identity_name_key' => 'forged-name',
            'identity_mail_key' => 'forged-mail',
        ]);
        $repository->save($user, validate: false);

        $row = $database->getConnection()->fetchAssociative(
            'SELECT identity_name_key, identity_mail_key FROM user WHERE uid = ?',
            [$user->id()],
        );
        self::assertIsArray($row);
        self::assertSame('community.owner', $row['identity_name_key']);
        self::assertSame('owner@example.test', $row['identity_mail_key']);
    }

    #[Test]
    public function schemaSyncKeepsHistoricalEmptyIdentityRowsValid(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = EntityType::fromClass(User::class);
        $legacy = new EntityType(
            id: $type->id(),
            label: $type->getLabel(),
            class: $type->getClass(),
            keys: $type->getKeys(),
            group: $type->getGroup(),
            _fieldDefinitions: $type->getFieldDefinitions(),
        );
        $schema = new SqlSchemaHandler($legacy, $database);
        $schema->ensureTable();
        foreach ([1, 2] as $id) {
            $database->insert('user')->fields(['uid', 'uuid', 'name', 'bundle', 'langcode', '_data'])->values([
                'uid' => $id,
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
                'name' => '',
                'bundle' => 'user',
                'langcode' => 'en',
                '_data' => '{}',
            ])->execute();
        }

        $authoritative = new SqlSchemaHandler($type, $database);
        $authoritative->ensureTable();
        $authoritative->assertRuntimeSchema();

        self::assertSame(2, (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM user'));
        self::assertSame(0, (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM user WHERE identity_name_key IS NOT NULL OR identity_mail_key IS NOT NULL'));

        $hydrated = new EntityInstantiator($type)->instantiate(User::class, [
            'uid' => 1,
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'name' => 'historical-owner',
            'mail' => 'historical@example.test',
            'identity_name_key' => null,
            'identity_mail_key' => null,
        ]);
        self::assertInstanceOf(User::class, $hydrated);
        $values = self::rawValues($hydrated);
        self::assertNull($values['identity_name_key']);
        self::assertNull($values['identity_mail_key']);
    }

    /** @return array{EntityRepository, DBALDatabase} */
    private static function repository(): array
    {
        EntityType::clearFromClassCache();
        $database = DBALDatabase::createSqlite();
        $type = EntityType::fromClass(User::class);
        (new SqlSchemaHandler($type, $database))->ensureTable();
        $repository = self::repositoryFor($database, $type);

        return [$repository, $database];
    }

    private static function repositoryFor(DBALDatabase $database, EntityType $type): EntityRepository
    {
        return V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver(new SingleConnectionResolver($database), 'uid'),
            new EventDispatcher(),
            database: $database,
        );
    }

    /** @return array<string, mixed> */
    private static function rawValues(User $user): array
    {
        $property = new \ReflectionProperty(EntityBase::class, 'valueContainer');
        $container = $property->getValue($user);
        self::assertInstanceOf(EntityValueContainer::class, $container);

        return $container->rawValues();
    }
}
