<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Password;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\User\UserCredentialSnapshot;
use Waaseyaa\Auth\Password\LegacyPasswordUpgrade;
use Waaseyaa\Auth\Password\LegacyPasswordVerifierChain;
use Waaseyaa\Auth\Password\PhpassPasswordVerifier;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\User\User;

/**
 * #2544: the one-time upgrade, over real SQLite so "the legacy value is gone"
 * is asserted against storage rather than against an in-memory object.
 */
#[CoversClass(LegacyPasswordUpgrade::class)]
#[CoversClass(LegacyPasswordVerifierChain::class)]
final class LegacyPasswordUpgradeTest extends TestCase
{
    private const string ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const string PASSWORD = 'the password they already have';

    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private EntityRepository $users;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $userType = new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        new SqlSchemaHandler($userType, $this->database)->ensureTable();
        $repository = $this->users = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $userType,
            new SqlStorageDriver(new SingleConnectionResolver($this->database), 'uid'),
            new EventDispatcher(),
            database: $this->database,
        );
        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            repositoryFactory: static fn(): EntityRepository => $repository,
        );
        $this->entityTypeManager->registerEntityType($userType);
    }

    #[Test]
    public function a_migrated_member_signs_in_with_the_password_they_already_have(): void
    {
        $user = $this->seedMigratedUser();

        self::assertTrue($this->upgrade()->verify($user, self::PASSWORD, $this->snapshotOf($user)));
    }

    #[Test]
    public function the_first_successful_login_writes_a_current_hash_and_removes_the_legacy_one(): void
    {
        $user = $this->seedMigratedUser();

        self::assertTrue($this->upgrade()->verify($user, self::PASSWORD, $this->snapshotOf($user)));

        $stored = $this->storedCredentials();
        self::assertNull($stored['legacy_pass'] ?? null, 'The legacy credential must be gone from storage.');
        self::assertIsString($stored['pass']);
        self::assertTrue(
            password_verify(self::PASSWORD, $stored['pass']),
            'A current Waaseyaa hash of the same password must have replaced it.',
        );
        self::assertStringStartsNotWith('$P$', $stored['pass']);

        // The in-memory object is deliberately NOT asserted here. `pass` and
        // `legacy_pass` are Internal: reading either off the entity needs an
        // audited capability, and the entity refuses array export outright.
        // That is the same protection that forces the upgrade itself to be
        // write-only. Storage is the assertable authority, and the in-memory
        // sync is covered by the-upgraded-account-then-authenticates-natively.
    }

    #[Test]
    public function the_upgraded_account_then_authenticates_natively(): void
    {
        $user = $this->seedMigratedUser();
        $this->upgrade()->verify($user, self::PASSWORD, $this->snapshotOf($user));

        $reloaded = $this->users->find('1');
        self::assertInstanceOf(User::class, $reloaded);

        // Second login: an empty chain, i.e. legacy verification switched off
        // entirely. It must still succeed, which is the whole point.
        $noLegacy = new LegacyPasswordUpgrade($this->entityTypeManager, new LegacyPasswordVerifierChain());
        self::assertTrue($noLegacy->verify($reloaded, self::PASSWORD, $this->snapshotOf($reloaded)));
        self::assertFalse($noLegacy->verify($reloaded, 'wrong', $this->snapshotOf($reloaded)));
    }

    #[Test]
    public function a_wrong_password_is_refused_and_writes_nothing(): void
    {
        $user = $this->seedMigratedUser();

        self::assertFalse($this->upgrade()->verify($user, 'not the password', $this->snapshotOf($user)));

        $stored = $this->storedCredentials();
        self::assertSame('', $stored['pass'] ?? '', 'A refused login must not issue a hash.');
        self::assertStringStartsWith(
            '$P$',
            (string) ($stored['legacy_pass'] ?? ''),
            'A refused login must leave the legacy credential in place for a later correct attempt.',
        );
    }

    #[Test]
    public function an_inactive_account_never_reaches_a_verifier(): void
    {
        $user = $this->seedMigratedUser(active: false);

        self::assertFalse($this->upgrade()->verify($user, self::PASSWORD, $this->snapshotOf($user)));
        self::assertStringStartsWith('$P$', (string) ($this->storedCredentials()['legacy_pass'] ?? ''));
    }

    /**
     * The structural guarantee: an account that already has a current hash
     * never consults its legacy value, so residue from a partially-applied
     * migration cannot pull it back onto the weaker credential.
     */
    #[Test]
    public function a_current_hash_is_never_downgraded_by_a_stale_legacy_value(): void
    {
        $user = $this->seedMigratedUser();
        $user->setPassword(password_hash('the current password', \PASSWORD_DEFAULT));
        $this->users->save($user);

        $upgrade = $this->upgrade();
        $snapshot = $this->snapshotOf($user);
        self::assertNotNull($snapshot->legacyPasswordHash, 'Residue is present …');

        self::assertTrue($upgrade->verify($user, 'the current password', $snapshot));
        self::assertFalse(
            $upgrade->verify($user, self::PASSWORD, $snapshot),
            '… and the legacy password no longer authenticates anything.',
        );
    }

    #[Test]
    public function an_unsupported_legacy_format_fails_safely(): void
    {
        $user = $this->seedMigratedUser();
        $user->setLegacyPassword('$wp$2y$10$' . str_repeat('a', 53));
        $this->users->save($user);

        self::assertFalse($this->upgrade()->verify($user, self::PASSWORD, $this->snapshotOf($user)));
        self::assertFalse($this->upgrade()->verify($user, 'anything', $this->snapshotOf($user)));
    }

    /**
     * Two logins racing. Both credentials were valid, so both must succeed, and
     * whichever lands second must not restore what the first removed.
     */
    #[Test]
    public function concurrent_successful_logins_do_not_restore_the_legacy_value(): void
    {
        $this->seedMigratedUser();

        // Two independently-loaded objects, as two requests would hold.
        $first = $this->users->find('1');
        $second = $this->users->find('1');
        self::assertInstanceOf(User::class, $first);
        self::assertInstanceOf(User::class, $second);

        $firstSnapshot = $this->snapshotOf($first);
        $secondSnapshot = $this->snapshotOf($second);

        $upgrade = $this->upgrade();
        self::assertTrue($upgrade->verify($first, self::PASSWORD, $firstSnapshot));
        // The second request verified against the snapshot it read BEFORE the
        // first request's write — the real interleaving.
        self::assertTrue($upgrade->verify($second, self::PASSWORD, $secondSnapshot));

        $stored = $this->storedCredentials();
        self::assertNull($stored['legacy_pass'] ?? null, 'The later write must not restore the legacy credential.');
        self::assertTrue(password_verify(self::PASSWORD, (string) $stored['pass']));
    }

    /**
     * A storage failure during the rewrite must not turn a valid credential
     * into a rejected login, and must not put the credential in a log.
     */
    #[Test]
    public function a_failed_upgrade_still_authenticates_and_never_logs_the_credential(): void
    {
        $user = $this->seedMigratedUser();
        $snapshot = $this->snapshotOf($user);
        $legacyHash = (string) $snapshot->legacyPasswordHash;

        $records = [];

        self::assertTrue(
            $this->failingUpgrade($records)->verify($user, self::PASSWORD, $snapshot),
            'The credential was valid; a bookkeeping failure must not reject the login.',
        );

        self::assertCount(1, $records);
        self::assertSame('auth.legacy_password_upgrade_failed', $records[0]['message']);
        $serialized = json_encode($records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($legacyHash, $serialized);
        self::assertStringNotContainsString(self::PASSWORD, $serialized);
        self::assertStringNotContainsString('storage is down', $serialized, 'The exception message must not be logged.');
    }

    // ---------------------------------------------------------------- helpers

    private function upgrade(): LegacyPasswordUpgrade
    {
        return new LegacyPasswordUpgrade(
            $this->entityTypeManager,
            new LegacyPasswordVerifierChain(new PhpassPasswordVerifier()),
        );
    }

    private function seedMigratedUser(bool $active = true): User
    {
        $user = new User([
            'uid' => 1,
            'uuid' => 'u-1',
            'name' => 'migrated.member',
            'mail' => 'member@example.test',
            'status' => $active,
            // The shape a migration produces: no native hash at all.
            'pass' => '',
            'legacy_pass' => self::phpassHash(self::PASSWORD, 'aBcD1234'),
        ]);
        $user->enforceIsNew();
        $this->users->save($user);

        $reloaded = $this->users->find('1');
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    private function snapshotOf(User $user): UserCredentialSnapshot
    {
        $stored = $this->storedCredentials();

        return new UserCredentialSnapshot(
            (bool) ($stored['status'] ?? false),
            (string) ($stored['pass'] ?? ''),
            is_string($stored['legacy_pass'] ?? null) && $stored['legacy_pass'] !== ''
                ? $stored['legacy_pass']
                : null,
        );
    }

    /** @return array<string, mixed> */
    private function storedCredentials(): array
    {
        // Straight out of the `_data` blob: a stored-bytes assertion must not
        // be satisfiable by anything the read path might normalize.
        $row = $this->database->getConnection()->fetchAssociative('SELECT _data FROM user WHERE uid = 1');
        self::assertIsArray($row);
        $data = json_decode((string) $row['_data'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * An upgrade whose repository throws on save, plus the log it writes to.
     *
     * @param list<array{message: string, context: array<string, mixed>}> $records
     */
    private function failingUpgrade(array &$records): LegacyPasswordUpgrade
    {
        $repository = $this->createStub(\Waaseyaa\Entity\Repository\EntityRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(fn(): ?\Waaseyaa\Entity\EntityInterface => $this->users->find('1'));
        $repository->method('save')->willThrowException(new \RuntimeException('storage is down'));

        $entityTypeManager = $this->createStub(\Waaseyaa\Entity\EntityTypeManagerInterface::class);
        $entityTypeManager->method('getRepository')->willReturn($repository);

        $logger = new class ($records) implements LoggerInterface {
            use \Waaseyaa\Foundation\Log\LoggerTrait;

            /** @param list<array{message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records) {}

            public function log(\Waaseyaa\Foundation\Log\LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        return new LegacyPasswordUpgrade(
            $entityTypeManager,
            new LegacyPasswordVerifierChain(new PhpassPasswordVerifier()),
            $logger,
        );
    }

    /** Openwall's reference hashing routine — see PhpassPasswordVerifierTest. */
    private static function phpassHash(string $password, string $salt): string
    {
        $digest = md5($salt . $password, true);
        $rounds = 1 << 13;
        do {
            $digest = md5($digest . $password, true);
        } while (--$rounds);

        $output = '';
        $i = 0;
        do {
            $value = \ord($digest[$i++]);
            $output .= self::ITOA64[$value & 0x3F];
            if ($i < 16) {
                $value |= \ord($digest[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3F];
            if ($i++ >= 16) {
                break;
            }
            if ($i < 16) {
                $value |= \ord($digest[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3F];
            if ($i++ >= 16) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3F];
        } while ($i < 16);

        return '$P$B' . $salt . $output;
    }
}
