<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Rekey;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\AuthServiceProvider;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Rekey\AuthTokenHmacRekeyAdapter;
use Waaseyaa\Auth\Tests\Support\AuthSchema;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRecord;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyState;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * Retained-red proof for the auth-token HMAC purpose owner.
 *
 * The adapter blocks a rotation while tokens are outstanding, which is correct WHEN THE
 * ADAPTER IS ENGAGED. Whether it is engaged is a separate question, and getting the two
 * confused was the defect: the provider contributed the adapter unconditionally, so an
 * application with a valid independent `AUTH_TOKEN_SECRET` had its outstanding tokens
 * block a `WAASEYAA_APP_SECRET` rotation that could not possibly invalidate them.
 *
 * Both halves are pinned here now. The engagement contract is
 * {@see self::provider_contributes_the_drain_adapter_only_under_derived_custody()} and
 * its adversarial siblings; the drain behaviour tests below run against DERIVED custody,
 * which is the only mode in which the adapter is ever reached.
 */
final class AuthTokenHmacRekeyAdapterRetainedRedTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function providerWith(array $config, ?DatabaseInterface $database): AuthServiceProvider
    {
        $provider = new AuthServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->setKernelServices(new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly ?DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });
        $provider->register();

        return $provider;
    }

    /** A valid explicit secret: trimmed, 32 characters, not a published placeholder. */
    private const string VALID_EXPLICIT_SECRET = 'abcdefghijklmnopqrstuvwxyz012345';

    /**
     * A signing key in DERIVED custody, which is the only mode in which the drain
     * adapter is ever engaged.
     *
     * The drain fixtures below previously signed with a 32-character literal that reads
     * as an independent explicit secret. The assertions were still true of the adapter,
     * but the fixture depicted the exact situation the defect got wrong: tokens signed
     * independently of the application master, being drained by it. Signing with real
     * derived material keeps the fixture honest about which mode it is exercising.
     */
    private function derivedSigningKey(): string
    {
        return ApplicationSecret::fromEnvironmentValue(
            'base64:' . base64_encode(str_repeat("\x41", 32)),
            'testing',
        )->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC);
    }

    #[Test]
    public function provider_binds_drain_or_expire_policy_for_the_longest_token_ttl(): void
    {
        $database = DBALDatabase::createSqlite();
        $provider = new AuthServiceProvider();
        $provider->setKernelContext('', ['environment' => 'testing'], []);
        $provider->setKernelServices(new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });
        $provider->register();
        $contributions = iterator_to_array($provider->applicationMasterRekeyContributions(), false);

        self::assertCount(1, $contributions);
        self::assertSame($database, $contributions[0]->adapter()->databaseAuthority());
        self::assertSame(AuthTokenHmacRekeyAdapter::ID, $contributions[0]->adapter()->id());
        self::assertSame([ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC], $contributions[0]->adapter()->purposeIds());
        self::assertSame([
            'id' => ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC,
            'owner_package' => 'waaseyaa/auth',
            'strategy' => ApplicationMasterPurposeStrategy::DrainOrExpire->value,
            'maximum_lifetime_seconds' => AuthConfig::LONGEST_TOKEN_TTL_SECONDS,
            'retention_seconds' => AuthConfig::LONGEST_TOKEN_TTL_SECONDS,
            'adapter_id' => AuthTokenHmacRekeyAdapter::ID,
            'rollback_behavior' => 'expire-outstanding-tokens',
        ], $contributions[0]->policies()[0]->canonicalRecord());
    }

    // ------------------------------------------------- engagement (the defect)

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function derivedCustodyConfigurations(): iterable
    {
        yield 'key absent' => [null];
        yield 'empty string' => [''];
        yield 'whitespace only' => ["  \t \n "];
    }

    #[Test]
    #[DataProvider('derivedCustodyConfigurations')]
    public function provider_contributes_the_drain_adapter_only_under_derived_custody(mixed $configured): void
    {
        $database = DBALDatabase::createSqlite();
        $provider = $this->providerWith(
            ['environment' => 'testing', 'auth' => ['token_secret' => $configured]],
            $database,
        );

        $contributions = iterator_to_array($provider->applicationMasterRekeyContributions(), false);

        self::assertCount(1, $contributions, 'derived custody must contribute the drain adapter');
        self::assertSame(AuthTokenHmacRekeyAdapter::ID, $contributions[0]->adapter()->id());
    }

    #[Test]
    public function a_valid_explicit_secret_contributes_no_application_master_auth_adapter(): void
    {
        $database = DBALDatabase::createSqlite();
        $provider = $this->providerWith(
            ['environment' => 'testing', 'auth' => ['token_secret' => self::VALID_EXPLICIT_SECRET]],
            $database,
        );

        // The heart of the defect. The signing key is independent of the application
        // master, so rotating that master cannot invalidate one outstanding token, and
        // the auth package must stay out of the rotation entirely.
        self::assertSame([], iterator_to_array($provider->applicationMasterRekeyContributions(), false));
    }

    #[Test]
    public function independently_signed_outstanding_tokens_do_not_block_master_rotation(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        // Outstanding tokens exist, signed with the independent explicit secret.
        new AuthTokenRepository($database, self::VALID_EXPLICIT_SECRET)->createToken(1, 'invite', 3600);
        new AuthTokenRepository($database, self::VALID_EXPLICIT_SECRET)->createToken(2, 'reset', 3600);

        $provider = $this->providerWith(
            ['environment' => 'testing', 'auth' => ['token_secret' => self::VALID_EXPLICIT_SECRET]],
            $database,
        );

        // Previously these tokens reached the drain adapter and raised
        // ApplicationMasterRekeyConflictException, blocking a rotation they are
        // unaffected by. Nothing is contributed now, so nothing can block.
        self::assertSame([], iterator_to_array($provider->applicationMasterRekeyContributions(), false));
    }

    #[Test]
    public function explicit_custody_does_not_even_require_a_database_authority(): void
    {
        // Classification precedes the database requirement, so an application in
        // explicit mode is not forced to stand up an authority for a contribution it
        // never makes.
        $provider = $this->providerWith(
            ['environment' => 'testing', 'auth' => ['token_secret' => self::VALID_EXPLICIT_SECRET]],
            null,
        );

        self::assertSame([], iterator_to_array($provider->applicationMasterRekeyContributions(), false));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidExplicitConfigurations(): iterable
    {
        yield 'too short' => ['short-secret'];
        yield 'one char under the floor' => [str_repeat('a', 31)];
        yield 'published placeholder' => ['changeme'];
        yield 'folded placeholder' => ['CHANGE_ME'];
        yield 'spaced placeholder' => ['change me'];
        yield 'non-string integer' => [12345];
        yield 'non-string array' => [['secret']];
        yield 'non-string bool' => [true];
    }

    #[Test]
    #[DataProvider('invalidExplicitConfigurations')]
    public function invalid_explicit_input_fails_closed_and_is_never_read_as_absent(mixed $configured): void
    {
        $provider = $this->providerWith(
            ['environment' => 'testing', 'auth' => ['token_secret' => $configured]],
            DBALDatabase::createSqlite(),
        );

        // Two fail-open readings are refused at once: it must not be treated as absent
        // (which would derive a key from the master), and it must not be treated as
        // explicit (which would silently suppress the drain adapter). It throws.
        $this->expectException(\RuntimeException::class);
        iterator_to_array($provider->applicationMasterRekeyContributions(), false);
    }

    #[Test]
    public function derived_mode_purpose_roster_is_unchanged_by_the_engagement_gate(): void
    {
        $provider = $this->providerWith(['environment' => 'testing'], DBALDatabase::createSqlite());
        $contribution = iterator_to_array($provider->applicationMasterRekeyContributions(), false)[0];

        self::assertSame([ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC], $contribution->adapter()->purposeIds());
        self::assertSame([
            'id' => ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC,
            'owner_package' => 'waaseyaa/auth',
            'strategy' => ApplicationMasterPurposeStrategy::DrainOrExpire->value,
            'maximum_lifetime_seconds' => AuthConfig::LONGEST_TOKEN_TTL_SECONDS,
            'retention_seconds' => AuthConfig::LONGEST_TOKEN_TTL_SECONDS,
            'adapter_id' => AuthTokenHmacRekeyAdapter::ID,
            'rollback_behavior' => 'expire-outstanding-tokens',
        ], $contribution->policies()[0]->canonicalRecord());
    }

    // ------------------------------------------------ drain behaviour (derived only)

    #[Test]
    public function under_derived_custody_outstanding_tokens_block_rotation(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $keyring = $this->keyring(2);
        $context = new ApplicationMasterRekeyContext(
            new ApplicationMasterRekeyRecord(
                'auth-rekey-1',
                hash('sha256', 'auth-request'),
                1,
                2,
                hash('sha256', 'auth-registry'),
                hash('sha256', 'auth-authorization'),
                'test-operator',
                1_000_100,
                1_002_000,
                ApplicationMasterRekeyState::EnumerateSnapshot,
                0,
                0,
                1_000_000,
                1_000_000,
            ),
            $keyring,
            $database,
        );
        $adapter = new AuthTokenHmacRekeyAdapter($database);

        $snapshot = $adapter->snapshot($context);
        $verification = $adapter->verify($context, $snapshot);
        self::assertSame(AuthTokenHmacRekeyAdapter::ID, $adapter->id());
        self::assertSame([ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC], $adapter->purposeIds());
        self::assertSame($database, $adapter->databaseAuthority());
        self::assertSame(0, $snapshot->totalRecords);
        self::assertSame(0, $verification[ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC]->verifiedRecords);

        new AuthTokenRepository($database, $this->derivedSigningKey())->createToken(1, 'invite', 60);

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('Outstanding auth tokens');
        $adapter->snapshot($context);
    }

    #[Test]
    public function drain_adapter_refuses_mutations_wrong_authority_and_inexact_snapshots(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $keyring = $this->keyring(2);
        $context = new ApplicationMasterRekeyContext(
            new ApplicationMasterRekeyRecord(
                'auth-rekey-2',
                hash('sha256', 'auth-request-2'),
                1,
                2,
                hash('sha256', 'auth-registry-2'),
                hash('sha256', 'auth-authorization-2'),
                'test-operator',
                1_000_100,
                1_002_000,
                ApplicationMasterRekeyState::EnumerateSnapshot,
                0,
                0,
                1_000_000,
                1_000_000,
            ),
            $keyring,
            $database,
        );
        $adapter = new AuthTokenHmacRekeyAdapter($database);
        $snapshot = $adapter->snapshot($context);
        $rollbackContext = new ApplicationMasterRekeyContext(
            $context->request,
            $this->keyring(1),
            $database,
        );
        $rollback = $adapter->rollbackSnapshot($rollbackContext);
        $verification = $adapter->verifyRollback($rollbackContext, $rollback);

        self::assertSame(0, $rollback->totalRecords);
        self::assertSame(0, $verification[ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC]->verifiedRecords);

        try {
            $adapter->transitionBatch($context, $snapshot, null, 10);
            self::fail('Transition batches must be refused.');
        } catch (ApplicationMasterRekeyConflictException $exception) {
            self::assertStringContainsString('cannot be rehashed', $exception->getMessage());
        }

        try {
            $adapter->rollbackBatch($rollbackContext, $rollback, null, 10);
            self::fail('Rollback batches must be refused.');
        } catch (ApplicationMasterRekeyConflictException $exception) {
            self::assertStringContainsString('cannot restore plaintext', $exception->getMessage());
        }

        try {
            $adapter->snapshot(new ApplicationMasterRekeyContext(
                $context->request,
                $keyring,
                DBALDatabase::createSqlite(),
            ));
            self::fail('Foreign database authority must be refused.');
        } catch (ApplicationMasterRekeyConflictException $exception) {
            self::assertStringContainsString('exact coordinator database authority', $exception->getMessage());
        }

        try {
            $adapter->verify($context, new ApplicationMasterInventorySnapshot(hash('sha256', 'wrong-snapshot'), 0));
            self::fail('Inexact snapshot tokens must be refused.');
        } catch (ApplicationMasterRekeyConflictException $exception) {
            self::assertStringContainsString('snapshot is not exact', $exception->getMessage());
        }

        $emptySchema = DBALDatabase::createSqlite();
        $emptyAdapter = new AuthTokenHmacRekeyAdapter($emptySchema);
        $emptyContext = new ApplicationMasterRekeyContext($context->request, $keyring, $emptySchema);
        self::assertSame(0, $emptyAdapter->snapshot($emptyContext)->totalRecords);

        $staleWriter = $this->keyring(1);
        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('active writer version is not exact');
        $adapter->snapshot(new ApplicationMasterRekeyContext($context->request, $staleWriter, $database));
    }

    #[Test]
    public function verify_refuses_tokens_that_appear_after_the_drain_snapshot(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $keyring = $this->keyring(2);
        $context = new ApplicationMasterRekeyContext(
            new ApplicationMasterRekeyRecord(
                'auth-rekey-3',
                hash('sha256', 'auth-request-3'),
                1,
                2,
                hash('sha256', 'auth-registry-3'),
                hash('sha256', 'auth-authorization-3'),
                'test-operator',
                1_000_100,
                1_002_000,
                ApplicationMasterRekeyState::EnumerateSnapshot,
                0,
                0,
                1_000_000,
                1_000_000,
            ),
            $keyring,
            $database,
        );
        $adapter = new AuthTokenHmacRekeyAdapter($database);
        $snapshot = $adapter->snapshot($context);
        new AuthTokenRepository($database, $this->derivedSigningKey())->createToken(1, 'invite', 60);

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('appeared after the drain snapshot');
        $adapter->verify($context, $snapshot);
    }

    private function keyring(int $activeVersion): ApplicationMasterKeyring
    {
        $purposes = new ApplicationMasterPurposeRegistry();
        $purposes->register(new ApplicationMasterPurposePolicy(
            ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC,
            'waaseyaa/auth',
            ApplicationMasterPurposeStrategy::DrainOrExpire,
            AuthConfig::LONGEST_TOKEN_TTL_SECONDS,
            AuthConfig::LONGEST_TOKEN_TTL_SECONDS,
            AuthTokenHmacRekeyAdapter::ID,
            'expire-outstanding-tokens',
        ));
        $purposes->freeze();
        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new AuthTokenSyntheticMasterProvider());
        $resolver->allow(
            'auth-token-synthetic-master',
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            ['testing'],
        );
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->freeze();

        return ApplicationMasterKeyring::fromReferences(
            $resolver,
            $activeVersion,
            SecretReference::create(
                'auth-token-synthetic-master',
                'master-v' . $activeVersion,
                SecretClass::ApplicationMaster,
                ApplicationMasterKeyring::MASTER_PURPOSE,
            ),
            [],
            $purposes,
        );
    }
}

final class AuthTokenSyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'auth-token-synthetic-master';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        return SensitiveValue::fromBytes(
            hash('sha256', $reference->identifier(), true),
            SecretClass::ApplicationMaster,
            $reference->identifier(),
        );
    }
}
