<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Password;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Auth\AuthManager;
use Waaseyaa\Auth\AuthServiceProvider;
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
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\Tests\Support\AuthenticationEligibilityFixture;
use Waaseyaa\User\User;

/**
 * #2544 wiring: the decision `AuthManager` makes, the chain the service
 * provider builds from configuration, and the chain's own dispatch.
 *
 * These are the seams where "the feature works" and "the feature is reachable"
 * come apart. A verifier that is never wired is indistinguishable, from a
 * member's side, from one that does not exist.
 */
#[CoversClass(AuthManager::class)]
#[CoversClass(AuthServiceProvider::class)]
#[CoversClass(LegacyPasswordVerifierChain::class)]
final class LegacyPasswordWiringTest extends TestCase
{
    private const string PASSWORD = 'the password they already have';

    // ------------------------------------------------------------- AuthManager

    #[Test]
    public function auth_manager_accepts_a_legacy_credential_when_the_upgrade_is_wired(): void
    {
        [$entityTypeManager, $user] = $this->seedMigratedUser();
        $manager = new AuthManager(
            new UserInternalFieldReaderFixture(),
            AuthenticationEligibilityFixture::policy(),
            new LegacyPasswordUpgrade(
                $entityTypeManager,
                new LegacyPasswordVerifierChain(new PhpassPasswordVerifier()),
            ),
        );

        self::assertTrue($manager->authenticate($user, self::PASSWORD));
        self::assertFalse($manager->authenticate($user, 'wrong'));
    }

    /**
     * Without the collaborator, behaviour is exactly the historical native-only
     * check — which is what makes this feature opt-in rather than a change
     * every consumer inherits.
     */
    #[Test]
    public function auth_manager_without_the_upgrade_keeps_the_native_only_check(): void
    {
        [, $legacyUser] = $this->seedMigratedUser();
        $manager = new AuthManager(new UserInternalFieldReaderFixture(), AuthenticationEligibilityFixture::policy());

        self::assertFalse($manager->authenticate($legacyUser, self::PASSWORD));

        [, $nativeUser] = $this->seedMigratedUser(native: true);
        self::assertTrue($manager->authenticate($nativeUser, self::PASSWORD));
    }

    #[Test]
    public function auth_manager_refuses_an_inactive_account_on_both_paths(): void
    {
        [$entityTypeManager, $user] = $this->seedMigratedUser(active: false);

        self::assertFalse(new AuthManager(new UserInternalFieldReaderFixture(), AuthenticationEligibilityFixture::policy())->authenticate($user, self::PASSWORD));
        self::assertFalse(
            new AuthManager(
                new UserInternalFieldReaderFixture(),
                AuthenticationEligibilityFixture::policy(),
                new LegacyPasswordUpgrade(
                    $entityTypeManager,
                    new LegacyPasswordVerifierChain(new PhpassPasswordVerifier()),
                ),
            )->authenticate($user, self::PASSWORD),
        );
    }

    // --------------------------------------------------------- provider wiring

    #[Test]
    public function the_default_chain_is_empty_so_nothing_is_accepted(): void
    {
        $chain = $this->chainFromConfig([]);

        self::assertFalse($chain->supports($this->phpassHash(self::PASSWORD, 'aBcD1234')));
        self::assertFalse($chain->verify(self::PASSWORD, $this->phpassHash(self::PASSWORD, 'aBcD1234')));
        self::assertNull($chain->formatName($this->phpassHash(self::PASSWORD, 'aBcD1234')));
    }

    #[Test]
    public function configuring_phpass_builds_a_chain_that_accepts_it(): void
    {
        $chain = $this->chainFromConfig(['auth' => ['legacy_passwords' => ['formats' => ['phpass']]]]);
        $hash = $this->phpassHash(self::PASSWORD, 'aBcD1234');

        self::assertTrue($chain->supports($hash));
        self::assertTrue($chain->verify(self::PASSWORD, $hash));
        self::assertSame('phpass', $chain->formatName($hash));
    }

    /**
     * A typo that degraded to "verifies nothing" would lock out every migrated
     * member with no signal at all, so it fails at boot instead.
     */
    #[Test]
    public function an_unknown_format_name_fails_loudly_at_boot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/phpas{0,2}/');

        $this->chainFromConfig(['auth' => ['legacy_passwords' => ['formats' => ['phpas']]]]);
    }

    #[Test]
    public function a_non_string_format_entry_also_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->chainFromConfig(['auth' => ['legacy_passwords' => ['formats' => [42]]]]);
    }

    #[Test]
    public function a_malformed_legacy_passwords_block_degrades_to_an_empty_chain(): void
    {
        // Not a refusal: absent or ill-shaped configuration means "not opted
        // in", which is the safe reading. Only a NAMED format that is unknown
        // is a mistake worth failing on.
        foreach ([
            ['auth' => ['legacy_passwords' => 'phpass']],
            ['auth' => ['legacy_passwords' => ['formats' => 'phpass']]],
            ['auth' => []],
        ] as $config) {
            self::assertFalse($this->chainFromConfig($config)->supports($this->phpassHash(self::PASSWORD, 'aBcD1234')));
        }
    }

    // ------------------------------------------------------------------ chain

    #[Test]
    public function the_chain_names_only_the_format_and_dispatches_to_one_verifier(): void
    {
        $chain = new LegacyPasswordVerifierChain(new PhpassPasswordVerifier());

        self::assertSame('chain', $chain->name());
        self::assertNull($chain->formatName(''), 'An empty stored value matches nothing.');
        self::assertNull($chain->formatName('$2y$10$' . str_repeat('a', 53)));
        self::assertFalse($chain->verify(self::PASSWORD, ''));
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $config */
    private function chainFromConfig(array $config): LegacyPasswordVerifierChain
    {
        $provider = new AuthServiceProvider();
        new \ReflectionProperty(\Waaseyaa\Foundation\ServiceProvider\ServiceProvider::class, 'config')
            ->setValue($provider, $config);

        $chain = new \ReflectionMethod(AuthServiceProvider::class, 'legacyVerifierChain')->invoke($provider);
        self::assertInstanceOf(LegacyPasswordVerifierChain::class, $chain);

        return $chain;
    }

    /** @return array{EntityTypeManager, User} */
    private function seedMigratedUser(bool $active = true, bool $native = false): array
    {
        $database = DBALDatabase::createSqlite();
        $userType = new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        new SqlSchemaHandler($userType, $database)->ensureTable();
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $userType,
            new SqlStorageDriver(new SingleConnectionResolver($database), 'uid'),
            new EventDispatcher(),
            database: $database,
        );
        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            repositoryFactory: static fn(): EntityRepository => $repository,
        );
        $entityTypeManager->registerEntityType($userType);

        $user = new User([
            'uid' => 1,
            'uuid' => 'u-1',
            'name' => 'migrated.member',
            'mail' => 'member@example.test',
            'status' => $active,
            'pass' => $native ? password_hash(self::PASSWORD, \PASSWORD_DEFAULT) : '',
            'legacy_pass' => $native ? null : $this->phpassHash(self::PASSWORD, 'aBcD1234'),
        ]);
        $user->enforceIsNew();
        $repository->save($user);

        $reloaded = $repository->find('1');
        self::assertInstanceOf(User::class, $reloaded);

        return [$entityTypeManager, $reloaded];
    }

    /** Openwall's reference hashing routine — see PhpassPasswordVerifierTest. */
    private function phpassHash(string $password, string $salt): string
    {
        $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $digest = md5($salt . $password, true);
        $rounds = 1 << 13;
        do {
            $digest = md5($digest . $password, true);
        } while (--$rounds);

        $output = '';
        $i = 0;
        do {
            $value = \ord($digest[$i++]);
            $output .= $itoa64[$value & 0x3F];
            if ($i < 16) {
                $value |= \ord($digest[$i]) << 8;
            }
            $output .= $itoa64[($value >> 6) & 0x3F];
            if ($i++ >= 16) {
                break;
            }
            if ($i < 16) {
                $value |= \ord($digest[$i]) << 16;
            }
            $output .= $itoa64[($value >> 12) & 0x3F];
            if ($i++ >= 16) {
                break;
            }
            $output .= $itoa64[($value >> 18) & 0x3F];
        } while ($i < 16);

        return '$P$B' . $salt . $output;
    }
}
