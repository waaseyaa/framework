<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Rekey;

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

/** Retained-red proof for the auth-token HMAC purpose owner. */
final class AuthTokenHmacRekeyAdapterRetainedRedTest extends TestCase
{
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

    #[Test]
    public function empty_inventory_snapshots_and_outstanding_tokens_block_rotation(): void
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
        self::assertSame(0, $snapshot->totalRecords);
        self::assertSame(0, $verification[ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC]->verifiedRecords);

        new AuthTokenRepository($database, 'abcdefghijklmnopqrstuvwxyz012345')->createToken(1, 'invite', 60);

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('Outstanding auth tokens');
        $adapter->snapshot($context);
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
