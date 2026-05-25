<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Oidc\Authorize\AuthorizationRequestValidator;
use Waaseyaa\Oidc\Authorize\AuthorizeController;
use Waaseyaa\Oidc\ClientRegistry\OidcClientLookup;
use Waaseyaa\Oidc\ClientRegistry\OidcClientSeeder;
use Waaseyaa\Oidc\Config\OidcIssuerConfig;
use Waaseyaa\Oidc\Consent\ConsentRepository;
use Waaseyaa\Oidc\Consent\ConsentScreenController;
use Waaseyaa\Oidc\Discovery\DiscoveryController;
use Waaseyaa\Oidc\Discovery\DiscoveryDocumentBuilder;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Oidc\Jwks\JwksController;
use Waaseyaa\Oidc\Jwks\JwksDocumentBuilder;
use Waaseyaa\Oidc\Key\RealKeyMaterialProvider;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\Keys\OidcKeyLoaderInterface;
use Waaseyaa\Oidc\Keys\PemFileKeyLoader;
use Waaseyaa\Oidc\Repository\AuthorizationCodeRepositoryInterface;
use Waaseyaa\Oidc\Repository\DatabaseAuthorizationCodeRepository;
use Waaseyaa\Oidc\Revoke\RevocationController;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\IdTokenMinter;
use Waaseyaa\Oidc\Token\InMemoryKeyMaterialProvider;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;
use Waaseyaa\Oidc\Token\PkceVerifier;
use Waaseyaa\Oidc\Token\RefreshTokenGrantHandler;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;
use Waaseyaa\Oidc\Token\TokenController;
use Waaseyaa\Oidc\Token\TokenRequestValidator;
use Waaseyaa\Oidc\Userinfo\UserinfoClaimResolver;
use Waaseyaa\Oidc\Userinfo\UserinfoController;

final class OidcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // OidcClient entity type registration
        $this->entityType(EntityType::fromClass(
            OidcClient::class,
            group: 'oidc',
        ));

        // Issuer config value object
        $this->singleton(
            OidcIssuerConfig::class,
            fn(): OidcIssuerConfig => new OidcIssuerConfig(issuerUrl: $this->resolveIssuer()),
        );

        // Key material — WP04 uses DB-backed RealKeyMaterialProvider when
        // SigningKeyRepository is available; falls back to file-based for existing installs.
        $this->singleton(
            OidcKeyLoaderInterface::class,
            fn(): OidcKeyLoaderInterface => $this->resolveKeyLoader(),
        );

        $this->singleton(
            SigningKeyRepository::class,
            function (): SigningKeyRepository {
                $database = $this->resolveDatabase();

                return new SigningKeyRepository(database: $database);
            },
        );

        $this->singleton(
            KeyMaterialProviderInterface::class,
            function (): KeyMaterialProviderInterface {
                // Use DB-backed provider if database is available; fall back to file-backed.
                try {
                    return new RealKeyMaterialProvider(
                        repository: $this->resolve(SigningKeyRepository::class),
                    );
                } catch (\Throwable) {
                    return new InMemoryKeyMaterialProvider(
                        keyLoader: $this->resolve(OidcKeyLoaderInterface::class),
                    );
                }
            },
        );

        // JWKS + discovery
        $this->singleton(
            JwksDocumentBuilder::class,
            static fn(): JwksDocumentBuilder => new JwksDocumentBuilder(),
        );

        $this->singleton(
            JwksController::class,
            fn(): JwksController => new JwksController(
                keyProvider: $this->resolve(KeyMaterialProviderInterface::class),
                builder: $this->resolve(JwksDocumentBuilder::class),
            ),
        );

        $this->singleton(
            DiscoveryDocumentBuilder::class,
            static fn(): DiscoveryDocumentBuilder => new DiscoveryDocumentBuilder(),
        );

        $this->singleton(
            DiscoveryController::class,
            fn(): DiscoveryController => new DiscoveryController(
                issuer: $this->resolveIssuer(),
                builder: $this->resolve(DiscoveryDocumentBuilder::class),
            ),
        );

        // Authorization code repository
        $this->singleton(
            AuthorizationCodeRepositoryInterface::class,
            function (): AuthorizationCodeRepositoryInterface {
                return new DatabaseAuthorizationCodeRepository(database: $this->resolveDatabase());
            },
        );

        // Token issuers
        $this->singleton(
            AccessTokenIssuer::class,
            fn(): AccessTokenIssuer => new AccessTokenIssuer(
                database: $this->resolveDatabase(),
            ),
        );

        $this->singleton(
            RefreshTokenIssuer::class,
            fn(): RefreshTokenIssuer => new RefreshTokenIssuer(
                database: $this->resolveDatabase(),
            ),
        );

        $this->singleton(
            IdTokenMinter::class,
            fn(): IdTokenMinter => new IdTokenMinter(
                keyProvider: $this->resolve(KeyMaterialProviderInterface::class),
            ),
        );

        $this->singleton(
            RefreshTokenGrantHandler::class,
            fn(): RefreshTokenGrantHandler => new RefreshTokenGrantHandler(
                refreshTokenIssuer: $this->resolve(RefreshTokenIssuer::class),
                accessTokenIssuer: $this->resolve(AccessTokenIssuer::class),
                idTokenMinter: $this->resolve(IdTokenMinter::class),
            ),
        );

        // Client registry
        $this->singleton(
            OidcClientLookup::class,
            function (): OidcClientLookup {
                $entityTypeManager = $this->resolve(EntityTypeManager::class);
                $storage = $entityTypeManager->getStorage('oidc_client');
                if (!$storage instanceof SqlEntityStorage) {
                    throw new \RuntimeException(
                        'OIDC client lookup requires SqlEntityStorage; got ' . $storage::class . '.',
                    );
                }

                return new OidcClientLookup($storage);
            },
        );

        // Request validators
        $this->singleton(
            AuthorizationRequestValidator::class,
            static fn(): AuthorizationRequestValidator => new AuthorizationRequestValidator(),
        );

        $this->singleton(
            PkceVerifier::class,
            static fn(): PkceVerifier => new PkceVerifier(),
        );

        $this->singleton(
            TokenRequestValidator::class,
            static fn(): TokenRequestValidator => new TokenRequestValidator(),
        );

        // Controllers
        $this->singleton(
            AuthorizeController::class,
            fn(): AuthorizeController => new AuthorizeController(
                clientLookup: $this->resolve(OidcClientLookup::class),
                validator: $this->resolve(AuthorizationRequestValidator::class),
                codeRepository: $this->resolve(AuthorizationCodeRepositoryInterface::class),
                consentRepository: $this->resolve(ConsentRepository::class),
                loginPath: $this->resolveLoginPath(),
            ),
        );

        $this->singleton(
            TokenController::class,
            fn(): TokenController => new TokenController(
                clientLookup: $this->resolve(OidcClientLookup::class),
                validator: $this->resolve(TokenRequestValidator::class),
                pkceVerifier: $this->resolve(PkceVerifier::class),
                codeRepository: $this->resolve(AuthorizationCodeRepositoryInterface::class),
                idTokenMinter: $this->resolve(IdTokenMinter::class),
                accessTokenIssuer: $this->resolve(AccessTokenIssuer::class),
                refreshTokenIssuer: $this->resolve(RefreshTokenIssuer::class),
                refreshGrantHandler: $this->resolve(RefreshTokenGrantHandler::class),
                issuer: $this->resolveIssuer(),
                clock: static fn(): \DateTimeImmutable => new \DateTimeImmutable(),
            ),
        );

        $this->singleton(
            RevocationController::class,
            fn(): RevocationController => new RevocationController(
                clientLookup: $this->resolve(OidcClientLookup::class),
                accessTokenIssuer: $this->resolve(AccessTokenIssuer::class),
                refreshTokenIssuer: $this->resolve(RefreshTokenIssuer::class),
            ),
        );

        // Consent
        $this->singleton(
            ConsentRepository::class,
            fn(): ConsentRepository => new ConsentRepository(
                database: $this->resolveDatabase(),
            ),
        );

        $this->singleton(
            ConsentScreenController::class,
            fn(): ConsentScreenController => new ConsentScreenController(
                consentRepository: $this->resolve(ConsentRepository::class),
                claimResolver: $this->resolve(UserinfoClaimResolver::class),
                codeRepository: $this->resolve(AuthorizationCodeRepositoryInterface::class),
                loginPath: $this->resolveLoginPath(),
            ),
        );

        // Userinfo
        $this->singleton(
            UserinfoClaimResolver::class,
            static fn(): UserinfoClaimResolver => new UserinfoClaimResolver(),
        );

        $this->singleton(
            UserinfoController::class,
            fn(): UserinfoController => new UserinfoController(
                idTokenMinter: $this->resolve(IdTokenMinter::class),
                accessTokenIssuer: $this->resolve(AccessTokenIssuer::class),
                entityTypeManager: $this->resolve(EntityTypeManager::class),
                entityAccessHandler: $this->resolve(EntityAccessHandler::class),
                claimResolver: $this->resolve(UserinfoClaimResolver::class),
                issuer: $this->resolveIssuer(),
            ),
        );
    }

    public function boot(): void
    {
        $this->seedOidcClientsFromConfig();
    }

    private function resolveDatabase(): DBALDatabase
    {
        $database = $this->resolve(DatabaseInterface::class);
        if (!$database instanceof DBALDatabase) {
            throw new \RuntimeException(
                'OIDC requires a DBALDatabase instance; got ' . $database::class . '.',
            );
        }

        return $database;
    }

    private function seedOidcClientsFromConfig(): void
    {
        $clients = $this->config['oidc']['clients'] ?? null;
        if (!is_array($clients) || $clients === []) {
            return;
        }

        try {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);
            $storage = $entityTypeManager->getStorage('oidc_client');
        } catch (\Throwable) {
            return;
        }

        if (!$storage instanceof SqlEntityStorage) {
            return;
        }

        new OidcClientSeeder($storage)->seed($clients);
    }

    private function resolveLoginPath(): string
    {
        $configured = $this->config['oidc']['login_path'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return '/login';
    }

    private function resolveIssuer(): string
    {
        $configIssuer = $this->config['oidc']['issuer'] ?? null;
        if (is_string($configIssuer) && $configIssuer !== '') {
            return $configIssuer;
        }

        $envIssuer = getenv('OIDC_ISSUER');
        if (is_string($envIssuer) && $envIssuer !== '') {
            return $envIssuer;
        }

        return 'http://localhost:8000';
    }

    private function resolveKeyLoader(): OidcKeyLoaderInterface
    {
        /** @var array<string, array{algorithm?: string, public_key_path: string, private_key_path?: string}>|null $configKeys */
        $configKeys = $this->config['oidc']['signing_keys'] ?? null;
        if (is_array($configKeys) && $configKeys !== []) {
            return PemFileKeyLoader::fromConfig($configKeys);
        }

        $envDir = getenv('OIDC_SIGNING_KEY_DIR');
        if (is_string($envDir) && $envDir !== '') {
            return PemFileKeyLoader::fromDirectory($envDir);
        }

        return PemFileKeyLoader::fromConfig([]);
    }
}
