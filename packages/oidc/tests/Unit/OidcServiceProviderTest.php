<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Oidc\Discovery\DiscoveryController;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Oidc\Jwks\JwksController;
use Waaseyaa\Oidc\Keys\OidcKeyLoaderInterface;
use Waaseyaa\Oidc\Keys\OpenSslKeyFactory;
use Waaseyaa\Oidc\Keys\PemFileKeyLoader;
use Waaseyaa\Oidc\OidcServiceProvider;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;

#[CoversClass(OidcServiceProvider::class)]
final class OidcServiceProviderTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        putenv('OIDC_ISSUER');
        putenv('OIDC_SIGNING_KEY_DIR');

        foreach ($this->tmpDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->tmpDirs = [];
    }

    #[Test]
    public function registerBindsDiscoveryControllerUsingConfigIssuer(): void
    {
        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [
            'oidc' => ['issuer' => 'https://id.example'],
        ], []);

        $provider->register();

        self::assertArrayHasKey(DiscoveryController::class, $provider->getBindings());

        $controller = $provider->resolve(DiscoveryController::class);
        self::assertInstanceOf(DiscoveryController::class, $controller);

        $body = json_decode((string) ($controller)()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://id.example', $body['issuer']);
    }

    #[Test]
    public function registerFallsBackToOidcIssuerEnvVarWhenConfigMissing(): void
    {
        putenv('OIDC_ISSUER=https://env.example');

        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [], []);

        $provider->register();

        $controller = $provider->resolve(DiscoveryController::class);
        $body = json_decode((string) ($controller)()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://env.example', $body['issuer']);
    }

    #[Test]
    public function resolveReturnsSameDiscoveryControllerInstanceOnRepeatedCalls(): void
    {
        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [
            'oidc' => ['issuer' => 'https://id.example'],
        ], []);

        $provider->register();

        self::assertSame(
            $provider->resolve(DiscoveryController::class),
            $provider->resolve(DiscoveryController::class),
        );
    }

    #[Test]
    public function registerBindsKeyLoaderFromSigningKeysConfig(): void
    {
        [$publicPath] = $this->writeRsaKeypair('config-key');

        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [
            'oidc' => [
                'issuer' => 'https://id.example',
                'signing_keys' => [
                    'config-key' => ['algorithm' => 'RS256', 'public_key_path' => $publicPath],
                ],
            ],
        ], []);

        $provider->register();

        self::assertArrayHasKey(OidcKeyLoaderInterface::class, $provider->getBindings());

        $loader = $provider->resolve(OidcKeyLoaderInterface::class);
        self::assertInstanceOf(PemFileKeyLoader::class, $loader);

        $keys = $loader->loadSigningKeys();
        self::assertCount(1, $keys);
        self::assertSame('config-key', $keys[0]->kid);
    }

    #[Test]
    public function registerBindsKeyLoaderFromSigningKeyDirEnvVarWhenConfigMissing(): void
    {
        $dir = sys_get_temp_dir() . '/waaseyaa_oidc_provider_' . uniqid();
        mkdir($dir, 0700, true);
        $this->tmpDirs[] = $dir;
        $this->writeRsaKeypair('env-key', $dir);

        putenv('OIDC_SIGNING_KEY_DIR=' . $dir);

        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [], []);

        $provider->register();

        $loader = $provider->resolve(OidcKeyLoaderInterface::class);
        $keys = $loader->loadSigningKeys();

        self::assertCount(1, $keys);
        self::assertSame('env-key', $keys[0]->kid);
    }

    #[Test]
    public function resolvingKeyLoaderThrowsWhenNoSigningKeysConfigured(): void
    {
        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [], []);

        $provider->register();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No OIDC signing keys configured');

        $provider->resolve(OidcKeyLoaderInterface::class);
    }

    #[Test]
    public function issuerJwksUsesDatabaseLifecycleEvenWhenFileKeysAreConfigured(): void
    {
        [$publicPath] = $this->writeRsaKeypair('jwks-key');

        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [
            'oidc' => [
                'issuer' => 'https://id.example',
                'signing_keys' => [
                    'jwks-key' => ['algorithm' => 'RS256', 'public_key_path' => $publicPath],
                ],
            ],
        ], []);

        $databaseKey = $this->attachSigningServices($provider);
        $provider->register();

        $controller = $provider->resolve(JwksController::class);
        self::assertInstanceOf(JwksController::class, $controller);

        $body = json_decode((string) ($controller)()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['keys']);
        self::assertSame($databaseKey->kid, $body['keys'][0]['kid']);
        self::assertNotSame('jwks-key', $body['keys'][0]['kid']);
        self::assertInstanceOf(
            \Waaseyaa\Oidc\Key\RealKeyMaterialProvider::class,
            $provider->resolve(KeyMaterialProviderInterface::class),
        );
    }

    #[Test]
    public function registerRegistersOidcClientEntityType(): void
    {
        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [], []);

        $provider->register();

        $entityTypes = $provider->getEntityTypes();
        $ids = array_map(fn ($t) => $t->id(), $entityTypes);
        self::assertContains('oidc_client', $ids);

        $oidcClient = null;
        foreach ($entityTypes as $type) {
            if ($type->id() === 'oidc_client') {
                $oidcClient = $type;
                break;
            }
        }
        self::assertNotNull($oidcClient);
        self::assertSame(OidcClient::class, $oidcClient->getClass());
        self::assertSame(
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
            $oidcClient->getKeys(),
        );

        $fields = $oidcClient->getFieldDefinitions();
        self::assertArrayHasKey('client_id', $fields);
        self::assertArrayHasKey('redirect_uris', $fields);
        self::assertArrayHasKey('scopes', $fields);
        self::assertArrayHasKey('client_secret_hash', $fields);
    }

    #[Test]
    public function contributesExactApplicationMasterPurposeOwners(): void
    {
        $database = DBALDatabase::createSqlite();
        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [
            'oidc' => [
                'signing_key_lifecycle' => [
                    'maximum_token_lifetime_seconds' => 7_776_000,
                    'maximum_clock_skew_seconds' => 300,
                    'jwks_cache_lifetime_seconds' => 86_400,
                    'propagation_margin_seconds' => 300,
                ],
            ],
        ], []);
        $provider->setKernelServices(new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });

        self::assertInstanceOf(ProvidesApplicationMasterRekeyContributionsInterface::class, $provider);
        $contributions = iterator_to_array($provider->applicationMasterRekeyContributions(), false);
        self::assertCount(3, $contributions);

        $records = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->policies() as $policy) {
                $records[$policy->id] = $policy->canonicalRecord();
            }
        }
        ksort($records, SORT_STRING);

        self::assertSame([
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION => [
                'id' => ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                'owner_package' => 'waaseyaa/oidc',
                'strategy' => ApplicationMasterPurposeStrategy::ReencryptCiphertext->value,
                'maximum_lifetime_seconds' => 3_600,
                'retention_seconds' => 3_900,
                'adapter_id' => 'oidc-access-token-v1',
                'rollback_behavior' => 'restore-predecessor-ciphertext-and-index',
            ],
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP => [
                'id' => ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
                'owner_package' => 'waaseyaa/oidc',
                'strategy' => ApplicationMasterPurposeStrategy::RecomputeLookupIndex->value,
                'maximum_lifetime_seconds' => 3_600,
                'retention_seconds' => 3_900,
                'adapter_id' => 'oidc-access-token-v1',
                'rollback_behavior' => 'restore-predecessor-ciphertext-and-index',
            ],
            ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION => [
                'id' => ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
                'owner_package' => 'waaseyaa/oidc',
                'strategy' => ApplicationMasterPurposeStrategy::ReencryptCiphertext->value,
                'maximum_lifetime_seconds' => 7_776_000,
                'retention_seconds' => 7_776_300,
                'adapter_id' => 'oidc-refresh-token-v1',
                'rollback_behavior' => 'restore-predecessor-ciphertext-and-index',
            ],
            ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP => [
                'id' => ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
                'owner_package' => 'waaseyaa/oidc',
                'strategy' => ApplicationMasterPurposeStrategy::RecomputeLookupIndex->value,
                'maximum_lifetime_seconds' => 7_776_000,
                'retention_seconds' => 7_776_300,
                'adapter_id' => 'oidc-refresh-token-v1',
                'rollback_behavior' => 'restore-predecessor-ciphertext-and-index',
            ],
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION => [
                'id' => ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
                'owner_package' => 'waaseyaa/oidc',
                'strategy' => ApplicationMasterPurposeStrategy::ReencryptCiphertext->value,
                'maximum_lifetime_seconds' => 7_776_000,
                'retention_seconds' => 7_863_000,
                'adapter_id' => 'oidc-signing-key-v1',
                'rollback_behavior' => 'restore-predecessor-ciphertext',
            ],
        ], $records);
    }

    #[Test]
    public function jwksControllerIsSingleton(): void
    {
        [$publicPath] = $this->writeRsaKeypair('singleton-key');

        $provider = new OidcServiceProvider();
        $provider->setKernelContext('/tmp/oidc-test', [
            'oidc' => [
                'signing_keys' => [
                    'singleton-key' => ['algorithm' => 'RS256', 'public_key_path' => $publicPath],
                ],
            ],
        ], []);

        $this->attachSigningServices($provider);
        $provider->register();

        self::assertSame(
            $provider->resolve(JwksController::class),
            $provider->resolve(JwksController::class),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function writeRsaKeypair(string $kid, ?string $dir = null): array
    {
        if ($dir === null) {
            $dir = sys_get_temp_dir() . '/waaseyaa_oidc_provider_' . uniqid();
            mkdir($dir, 0700, true);
            $this->tmpDirs[] = $dir;
        }

        $keyPair = new OpenSslKeyFactory()->generateRsaKeyPair();

        $publicPath = $dir . '/' . $kid . '.pub.pem';
        $privatePath = $dir . '/' . $kid . '.key.pem';
        file_put_contents($publicPath, $keyPair['public']);
        file_put_contents($privatePath, $keyPair['private']);

        return [$publicPath, $privatePath];
    }

    private function attachSigningServices(OidcServiceProvider $provider): \Waaseyaa\Oidc\Keys\SigningKey
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        $applicationSecret = ApplicationSecret::fromEnvironmentValue(
            'base64:' . base64_encode(random_bytes(32)),
            'testing',
        );
        $key = new \Waaseyaa\Oidc\Key\SigningKeyRepository(
            $database,
            $applicationSecret->derive(ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION),
        )->initialize();
        $provider->setKernelServices(new class ($database, $applicationSecret) implements KernelServicesInterface {
            public function __construct(
                private readonly DatabaseInterface $database,
                private readonly ApplicationSecret $applicationSecret,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    DatabaseInterface::class => $this->database,
                    ApplicationSecret::class => $this->applicationSecret,
                    default => null,
                };
            }
        });

        return $key;
    }
}
