<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\AuthServiceProvider;
use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Tests\Support\AuthSchema;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProviderCapabilitySource;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Tests\Support\AuthenticationEligibilityFixture;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\Authentication\AuthenticationEligibilityInterface;
use Waaseyaa\User\Authentication\AuthenticationStage;
use Waaseyaa\User\User;

#[CoversClass(AuthServiceProvider::class)]
final class AuthServiceProviderTest extends TestCase
{
    private const string EXPLICIT_SECRET = 'abcdefghijklmnopqrstuvwxyz012345';

    private string|false $appEnvironment;

    private bool $envHadAppEnvironment;

    private mixed $envAppEnvironment;

    protected function setUp(): void
    {
        $this->appEnvironment = getenv('APP_ENV');
        $this->envHadAppEnvironment = array_key_exists('APP_ENV', $_ENV);
        $this->envAppEnvironment = $_ENV['APP_ENV'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->appEnvironment === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->appEnvironment);
        }

        if ($this->envHadAppEnvironment) {
            $_ENV['APP_ENV'] = $this->envAppEnvironment;
        } else {
            unset($_ENV['APP_ENV']);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('canonicalEnvironmentCases')]
    public function auth_configuration_uses_the_kernel_environment_contract(
        array $config,
        string|false $processEnvironment,
        mixed $envSuperglobal,
        MailMissingPolicy $expectedPolicy,
    ): void {
        if ($processEnvironment === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $processEnvironment);
        }

        if ($envSuperglobal === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $envSuperglobal;
        }

        $provider = $this->providerWith($config);
        $provider->register();

        $authConfig = $provider->resolve(AuthConfig::class);
        self::assertInstanceOf(AuthConfig::class, $authConfig);
        self::assertSame($expectedPolicy, $authConfig->mailMissingPolicy);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|false, mixed, MailMissingPolicy}>
     */
    public static function canonicalEnvironmentCases(): iterable
    {
        yield 'process injection is authoritative when $_ENV is absent' => [
            [],
            'local',
            null,
            MailMissingPolicy::DevLog,
        ];
        yield 'canonical config overrides a conflicting superglobal' => [
            ['environment' => 'local'],
            'production',
            'production',
            MailMissingPolicy::DevLog,
        ];
        yield 'legacy app_env cannot override the kernel environment' => [
            ['app_env' => 'local'],
            'production',
            'local',
            MailMissingPolicy::Fail,
        ];
        yield 'empty canonical config remains non-development' => [
            ['environment' => ''],
            'local',
            'local',
            MailMissingPolicy::Fail,
        ];
        yield 'whitespace canonical config remains non-development' => [
            ['environment' => ' '],
            'local',
            'local',
            MailMissingPolicy::Fail,
        ];
        yield 'zero canonical config remains non-development' => [
            ['environment' => '0'],
            'local',
            'local',
            MailMissingPolicy::Fail,
        ];
        yield 'missing environment defaults to production' => [
            [],
            false,
            null,
            MailMissingPolicy::Fail,
        ];
    }

    #[Test]
    public function missing_token_secret_fails_loudly_in_production(): void
    {
        $provider = $this->providerWith(['environment' => 'production']);
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/application-secret custody/');

        $provider->resolve(AuthTokenRepositoryInterface::class);
    }

    #[Test]
    public function change_me_literal_is_rejected_in_production(): void
    {
        $provider = $this->providerWith([
            'environment' => 'production',
            'auth' => ['token_secret' => 'change-me'],
        ]);
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/placeholder/');

        $provider->resolve(AuthTokenRepositoryInterface::class);
    }

    #[Test]
    public function configured_token_secret_resolves(): void
    {
        $provider = $this->providerWith([
            'environment' => 'production',
            'auth' => ['token_secret' => self::EXPLICIT_SECRET],
        ]);
        $provider->register();

        $repo = $provider->resolve(AuthTokenRepositoryInterface::class);

        $this->assertInstanceOf(AuthTokenRepository::class, $repo);
    }

    #[Test]
    public function absent_token_secret_does_not_hmac_with_raw_app_secret_bytes(): void
    {
        $master = random_bytes(32);
        $applicationSecret = ApplicationSecret::fromEnvironmentValue(
            'base64:' . base64_encode($master),
            'testing',
        );
        $provider = $this->providerWith(
            [
                'environment' => 'testing',
                'app_secret' => $master,
                'auth' => [],
            ],
            $applicationSecret,
        );
        $provider->register();

        $repo = $provider->resolve(AuthTokenRepositoryInterface::class);
        $this->assertInstanceOf(AuthTokenRepository::class, $repo);
        $plain = $repo->createToken(3, 'password_reset', 60);

        $raw = new AuthTokenRepository($this->databaseFrom($provider), $master);
        $derived = new AuthTokenRepository(
            $this->databaseFrom($provider),
            $applicationSecret->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC),
        );

        self::assertNull($raw->validateToken($plain, 'password_reset'));
        self::assertIsArray($derived->validateToken($plain, 'password_reset'));
    }

    #[Test]
    public function mixed_case_placeholder_and_short_explicit_values_always_fail(): void
    {
        foreach (['Change-Me', '  change-me  ', 'too-short', "\tsecret-with-spaces-not-32"] as $invalid) {
            $provider = $this->providerWith([
                'environment' => 'local',
                'auth' => ['token_secret' => $invalid],
            ]);
            $provider->register();

            try {
                $provider->resolve(AuthTokenRepositoryInterface::class);
                self::fail('Explicit invalid auth.token_secret was accepted: ' . $invalid);
            } catch (\RuntimeException $exception) {
                self::assertDoesNotMatchRegularExpression('/Change-Me|too-short|secret-with-spaces/', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function non_production_without_secret_derives_from_application_secret(): void
    {
        $master = random_bytes(32);
        $applicationSecret = ApplicationSecret::fromEnvironmentValue(
            'base64:' . base64_encode($master),
            'local',
        );
        $provider = $this->providerWith(['environment' => 'local'], $applicationSecret);
        $provider->register();

        $repo = $provider->resolve(AuthTokenRepositoryInterface::class);

        $this->assertInstanceOf(AuthTokenRepository::class, $repo);
        $plain = $repo->createToken(4, 'invite', 60);
        $derived = new AuthTokenRepository(
            $this->databaseFrom($provider),
            $applicationSecret->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC),
        );
        self::assertIsArray($derived->validateToken($plain, 'invite'));
    }

    #[Test]
    public function valid_explicit_secret_is_independent_of_application_secret(): void
    {
        $master = random_bytes(32);
        $applicationSecret = ApplicationSecret::fromEnvironmentValue(
            'base64:' . base64_encode($master),
            'production',
        );
        $provider = $this->providerWith(
            [
                'environment' => 'production',
                'auth' => ['token_secret' => self::EXPLICIT_SECRET],
            ],
            $applicationSecret,
        );
        $provider->register();

        $repo = $provider->resolve(AuthTokenRepositoryInterface::class);
        $plain = $repo->createToken(8, 'email_verification', 60);
        $database = $this->databaseFrom($provider);

        self::assertIsArray(new AuthTokenRepository($database, self::EXPLICIT_SECRET)->validateToken($plain, 'email_verification'));
        self::assertNull(new AuthTokenRepository(
            $database,
            $applicationSecret->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC),
        )->validateToken($plain, 'email_verification'));
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    public function bearer_token_store_binding_resolves_the_database_store(): void
    {
        $provider = $this->providerWith([]);
        $provider->register();

        $store = $provider->resolve(\Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface::class);

        $this->assertInstanceOf(\Waaseyaa\Auth\Token\Bearer\DatabaseBearerTokenStore::class, $store);
    }

    #[Test]
    public function the_domain_provider_does_not_own_console_commands(): void
    {
        $provider = $this->providerWith([]);
        $provider->register();

        $this->assertNotInstanceOf(
            \Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface::class,
            $provider,
        );
    }

    #[Test]
    public function auth_extension_registry_binding_composes_kernel_provider_capabilities(): void
    {
        $source = new ProviderCapabilitySource(static fn(): array => []);
        $events = new SymfonyEventDispatcherAdapter();
        $provider = $this->providerWith(['environment' => 'testing'], capabilitySource: $source, events: $events);
        $provider->register();

        $registry = $provider->resolve(AuthExtensionRegistry::class);

        self::assertInstanceOf(AuthExtensionRegistry::class, $registry);
        self::assertSame([], $registry->owners());
    }

    #[Test]
    public function auth_owned_eligibility_cannot_be_shadowed_by_a_kernel_interface_binding(): void
    {
        $shadow = AuthenticationEligibilityFixture::policy();
        $internalFields = new UserInternalFieldReaderFixture();
        $provider = new AuthServiceProvider();
        $provider->setKernelContext('', [
            'environment' => 'testing',
            'auth' => ['require_verified_email' => true],
        ], []);
        $provider->setKernelServices(new class ($shadow, $internalFields) implements KernelServicesInterface {
            public function __construct(
                private readonly AuthenticationEligibilityInterface $shadow,
                private readonly UserInternalFieldReaderInterface $internalFields,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    AuthenticationEligibilityInterface::class => $this->shadow,
                    UserInternalFieldReaderInterface::class => $this->internalFields,
                    default => null,
                };
            }
        });
        $provider->register();

        $canonical = $provider->resolve(VerifiedEmailAuthenticationEligibility::class);
        self::assertSame($canonical, $provider->resolve(AuthenticationEligibilityInterface::class));
        self::assertNotSame($shadow, $canonical);
        self::assertFalse($canonical->allows(
            new User(['uid' => 7, 'status' => true, 'mail' => 'member@example.test', 'email_verified' => false]),
            AuthenticationStage::PasswordLogin,
        ));
    }

    #[Test]
    public function rekey_composition_refuses_a_missing_database_authority(): void
    {
        $provider = new AuthServiceProvider();
        $provider->setKernelContext('', ['environment' => 'testing'], []);
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return null;
            }
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('kernel database authority');
        iterator_to_array($provider->applicationMasterRekeyContributions());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function providerWith(
        array $config,
        ?ApplicationSecret $applicationSecret = null,
        ?ProviderCapabilitySource $capabilitySource = null,
        ?EventDispatcherInterface $events = null,
    ): AuthServiceProvider {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $provider = new AuthServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->setKernelServices(new class ($database, $applicationSecret, $capabilitySource, $events) implements KernelServicesInterface {
            public function __construct(
                private readonly DBALDatabase $database,
                private readonly ?ApplicationSecret $applicationSecret,
                private readonly ?ProviderCapabilitySource $capabilitySource,
                private readonly ?EventDispatcherInterface $events,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    DatabaseInterface::class => $this->database,
                    ApplicationSecret::class => $this->applicationSecret,
                    ProviderCapabilitySource::class => $this->capabilitySource,
                    EventDispatcherInterface::class => $this->events,
                    default => null,
                };
            }
        });

        return $provider;
    }

    private function databaseFrom(AuthServiceProvider $provider): DatabaseInterface
    {
        $database = $provider->resolve(DatabaseInterface::class);
        self::assertInstanceOf(DatabaseInterface::class, $database);

        return $database;
    }
}
