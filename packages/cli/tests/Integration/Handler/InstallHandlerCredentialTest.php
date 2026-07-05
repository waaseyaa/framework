<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\InstallHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\User\User;

/**
 * Regression test for audit A7 F1 (R10 WP1): InstallHandler must create the
 * admin user through the same hashed-credential path as UserCreateHandler.
 *
 * Before the fix, InstallHandler wrote `email`/`password` (unrecognized
 * entity keys) instead of `mail`/`pass`. SqlStorageDriver::splitForWrite()
 * routes unrecognized keys verbatim into the `_data` JSON blob, so:
 *   (a) `mail`/`pass` were never populated -> checkPassword() always
 *       returns false -> admin login is silently broken, and
 *   (b) the operator's plaintext --admin-password was persisted at rest,
 *       in cleartext, inside the `_data` blob under the key `password`.
 *
 * This test wires the *real* SqlStorageDriver + EntityRepository + User
 * entity against an in-memory SQLite database (mirroring
 * packages/oidc/tests/Integration/Userinfo/UserinfoControllerTest.php) so
 * the exploit is provable end-to-end, not just at the array-shape level.
 */
#[CoversClass(InstallHandler::class)]
final class InstallHandlerCredentialTest extends TestCase
{
    private const string ADMIN_PASSWORD = 'correct horse battery staple';

    private function makeDefinition(InstallHandler $handler): HandlerCommand
    {
        return new HandlerCommand(
            name: 'install',
            description: 'Install Waaseyaa with initial configuration',
            options: [
                new HandlerOption(name: 'site-name', mode: HandlerOptionMode::Required, description: 'The name of the site', default: 'Waaseyaa'),
                new HandlerOption(name: 'site-mail', mode: HandlerOptionMode::Required, description: 'Site email address', default: 'admin@example.com'),
                new HandlerOption(name: 'admin-email', mode: HandlerOptionMode::Required, description: 'Admin user email', default: 'admin@example.com'),
                new HandlerOption(name: 'admin-password', mode: HandlerOptionMode::Required, description: 'Admin user password'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Container::get not used in this test');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    /**
     * @return array{0: EntityRepository, 1: DBALDatabase, 2: EntityType}
     */
    private function makeRealUserRepository(): array
    {
        $userDb = DBALDatabase::createSqlite();
        $userEntityType = new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        $schemaHandler = new SqlSchemaHandler($userEntityType, $userDb);
        $schemaHandler->ensureTable();
        $schemaHandler->addFieldColumns([
            'name' => ['type' => 'varchar', 'length' => 255, 'not null' => false],
            'mail' => ['type' => 'varchar', 'length' => 255, 'not null' => false],
            'email_verified' => ['type' => 'int', 'not null' => true, 'default' => 0],
            'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
            'created' => ['type' => 'int', 'not null' => false],
        ]);

        $repository = new EntityRepository(
            $userEntityType,
            new SqlStorageDriver(new SingleConnectionResolver($userDb), 'uid'),
            new EventDispatcher(),
            database: $userDb,
        );

        return [$repository, $userDb, $userEntityType];
    }

    private function makeEntityTypeManager(EntityRepository $repository, EntityType $userEntityType): EntityTypeManager
    {
        $manager = new EntityTypeManager(
            eventDispatcher: new EventDispatcher(),
            repositoryFactory: static fn (): EntityRepository => $repository,
        );
        $manager->registerEntityType($userEntityType);

        return $manager;
    }

    /**
     * Runs the real InstallHandler with the given options against a real
     * SQLite-backed user repository and returns [$repository, $userDb] so each
     * test method can assert against the persisted state independently.
     *
     * @param array<string, string> $options
     * @return array{0: EntityRepository, 1: DBALDatabase}
     */
    private function runInstall(array $options): array
    {
        [$repository, $userDb, $userEntityType] = $this->makeRealUserRepository();
        $entityTypeManager = $this->makeEntityTypeManager($repository, $userEntityType);

        $mockConfigManager = $this->createMock(ConfigManagerInterface::class);
        $mockConfigManager->method('getActiveStorage')->willReturn(new MemoryStorage());

        $handler = new InstallHandler(
            entityTypeManager: $entityTypeManager,
            configManager: $mockConfigManager,
        );

        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap($options);

        self::assertSame(0, $tester->getExitCode());

        return [$repository, $userDb];
    }

    /**
     * The CRITICAL security regression, guarded on its OWN test method so it is
     * genuinely red-first: PHPUnit halts a method at the first failed
     * assertion, so if this shared a method with the login assertion below the
     * plaintext-at-rest check would never run against pre-fix code. This method
     * asserts ONLY plaintext-absence + hash-shape, so it fails specifically at
     * the plaintext assertion when the wrong-key/unhashed regression returns.
     */
    #[Test]
    public function noPlaintextPasswordIsPersistedAtRest(): void
    {
        [, $userDb] = $this->runInstall([
            '--admin-email' => 'admin@example.com',
            '--admin-password' => self::ADMIN_PASSWORD,
        ]);

        // No plaintext password may appear ANYWHERE in the persisted row,
        // including the `_data` JSON blob.
        $row = $userDb->getConnection()->fetchAssociative('SELECT * FROM user WHERE uid = 1');
        self::assertIsArray($row, 'Expected a persisted admin row.');

        $rowAsJson = json_encode($row, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(
            self::ADMIN_PASSWORD,
            $rowAsJson,
            'The plaintext admin password must never be persisted at rest, in any column (including `_data`).',
        );

        // `pass` is not a #[Field]-materialized column on User, so the HASH
        // lands in the `_data` JSON blob — that is fine: it is a bcrypt hash,
        // not the plaintext, and stored under the correct key.
        self::assertArrayHasKey('_data', $row);
        $data = json_decode((string) $row['_data'], associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('pass', $data, 'The hashed password must be stored under the `pass` key.');
        self::assertMatchesRegularExpression(
            '/^\$2y\$/',
            (string) $data['pass'],
            'The persisted password must be a bcrypt hash, not a plaintext value.',
        );
        self::assertArrayNotHasKey('password', $data, 'No value should ever be stored under the wrong key `password`.');
    }

    #[Test]
    public function adminCanAuthenticateWithTheProvidedPasswordAfterInstall(): void
    {
        [$repository] = $this->runInstall([
            '--admin-email' => 'admin@example.com',
            '--admin-password' => self::ADMIN_PASSWORD,
        ]);

        // The admin must be able to authenticate with the password the operator
        // supplied at install time, and the email must land in the `mail` key.
        /** @var User|null $admin */
        $admin = $repository->find('1');
        self::assertNotNull($admin, 'InstallHandler must have persisted the admin user.');
        self::assertInstanceOf(User::class, $admin);
        self::assertSame('admin@example.com', $admin->getEmail(), 'Admin email must land in the `mail` entity key.');
        self::assertTrue(
            $admin->checkPassword(self::ADMIN_PASSWORD),
            'Admin must be able to authenticate with the --admin-password supplied at install time.',
        );
    }

    #[Test]
    public function adminMailColumnIsPopulated(): void
    {
        [, $userDb] = $this->runInstall([
            '--admin-email' => 'root@waaseyaa.test',
        ]);

        $row = $userDb->getConnection()->fetchAssociative('SELECT * FROM user WHERE uid = 1');
        self::assertIsArray($row);
        self::assertSame('root@waaseyaa.test', $row['mail'] ?? null, 'The admin-email option must land in the `mail` column.');
    }
}
