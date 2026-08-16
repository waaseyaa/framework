<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
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
use Waaseyaa\Oidc\Key\SigningKeyEmergencyRevocationService;
use Waaseyaa\Oidc\Key\SigningKeyLifecyclePolicy;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\Keys\OidcKeyLoaderInterface;
use Waaseyaa\Oidc\Keys\PemFileKeyLoader;
use Waaseyaa\Oidc\Keys\SigningAlgorithmPolicy;
use Waaseyaa\Oidc\Rekey\OidcAccessTokenRekeyAdapter;
use Waaseyaa\Oidc\Rekey\OidcRefreshTokenRekeyAdapter;
use Waaseyaa\Oidc\Rekey\OidcSigningKeyRekeyAdapter;
use Waaseyaa\Oidc\Repository\AuthorizationCodeRepositoryInterface;
use Waaseyaa\Oidc\Repository\DatabaseAuthorizationCodeRepository;
use Waaseyaa\Oidc\Revoke\RevocationController;
use Waaseyaa\Oidc\Security\LegacyOidcSecretMigrator;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\IdTokenMinter;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;
use Waaseyaa\Oidc\Token\PkceVerifier;
use Waaseyaa\Oidc\Token\RefreshTokenGrantHandler;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;
use Waaseyaa\Oidc\Token\TokenController;
use Waaseyaa\Oidc\Token\TokenRequestValidator;
use Waaseyaa\Oidc\Userinfo\UserinfoClaimResolver;
use Waaseyaa\Oidc\Userinfo\UserinfoController;

final class OidcServiceProvider extends ServiceProvider implements ProvidesApplicationMasterRekeyContributionsInterface
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

        // File-backed loaders remain available for explicit callers. Issuer signing uses
        // the encrypted DB repository and propagates configuration/decryption failures.
        $this->singleton(
            OidcKeyLoaderInterface::class,
            fn(): OidcKeyLoaderInterface => $this->resolveKeyLoader(),
        );

        $this->singleton(
            SigningKeyLifecyclePolicy::class,
            fn(): SigningKeyLifecyclePolicy => new SigningKeyLifecyclePolicy(
                maximumTokenLifetimeSeconds: $this->lifecycleDuration(
                    'maximum_token_lifetime_seconds',
                    7_776_000,
                ),
                maximumClockSkewSeconds: $this->lifecycleDuration(
                    'maximum_clock_skew_seconds',
                    300,
                ),
                jwksCacheLifetimeSeconds: $this->lifecycleDuration(
                    'jwks_cache_lifetime_seconds',
                    86_400,
                ),
                propagationMarginSeconds: $this->lifecycleDuration(
                    'propagation_margin_seconds',
                    300,
                ),
            ),
        );

        $this->singleton(
            SigningAlgorithmPolicy::class,
            static fn(): SigningAlgorithmPolicy => new SigningAlgorithmPolicy(),
        );

        $this->singleton(
            SigningKeyRepository::class,
            function (): SigningKeyRepository {
                $database = $this->resolveDatabase();
                [$keyring, $legacyKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
                );

                return new SigningKeyRepository(
                    database: $database,
                    encryptionKey: $legacyKey,
                    lifecyclePolicy: $this->resolve(SigningKeyLifecyclePolicy::class),
                    keyring: $keyring,
                );
            },
        );

        $this->singleton(
            KeyMaterialProviderInterface::class,
            fn(): KeyMaterialProviderInterface => new RealKeyMaterialProvider(
                repository: $this->resolve(SigningKeyRepository::class),
            ),
        );

        $this->singleton(
            SigningKeyEmergencyRevocationService::class,
            fn(): SigningKeyEmergencyRevocationService => new SigningKeyEmergencyRevocationService(
                database: $this->resolveDatabase(),
                repository: $this->resolve(SigningKeyRepository::class),
                policy: $this->resolve(SigningKeyLifecyclePolicy::class),
            ),
        );

        // JWKS + discovery
        $this->singleton(
            JwksDocumentBuilder::class,
            fn(): JwksDocumentBuilder => new JwksDocumentBuilder(
                algorithmPolicy: $this->resolve(SigningAlgorithmPolicy::class),
            ),
        );

        $this->singleton(
            JwksController::class,
            fn(): JwksController => new JwksController(
                keyProvider: $this->resolve(KeyMaterialProviderInterface::class),
                builder: $this->resolve(JwksDocumentBuilder::class),
                lifecyclePolicy: $this->resolve(SigningKeyLifecyclePolicy::class),
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
            function (): AccessTokenIssuer {
                [$keyring, $encryptionKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                );
                [, $lookupKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
                );

                return new AccessTokenIssuer(
                    database: $this->resolveDatabase(),
                    encryptionKey: $encryptionKey,
                    lookupKey: $lookupKey,
                    keyring: $keyring,
                );
            },
        );

        $this->singleton(
            RefreshTokenIssuer::class,
            function (): RefreshTokenIssuer {
                [$keyring, $encryptionKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
                );
                [, $lookupKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
                );

                return new RefreshTokenIssuer(
                    database: $this->resolveDatabase(),
                    encryptionKey: $encryptionKey,
                    lookupKey: $lookupKey,
                    keyring: $keyring,
                );
            },
        );

        $this->singleton(
            LegacyOidcSecretMigrator::class,
            function (): LegacyOidcSecretMigrator {
                [$keyring, $signingKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
                );
                [, $accessEncryptionKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                );
                [, $accessLookupKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
                );
                [, $refreshEncryptionKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
                );
                [, $refreshLookupKey] = $this->runtimeCustody(
                    ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
                );

                return new LegacyOidcSecretMigrator(
                    database: $this->resolveDatabase(),
                    signingKeyEncryptionKey: $signingKey,
                    accessTokenEncryptionKey: $accessEncryptionKey,
                    accessTokenLookupKey: $accessLookupKey,
                    refreshTokenEncryptionKey: $refreshEncryptionKey,
                    refreshTokenLookupKey: $refreshLookupKey,
                    keyring: $keyring,
                );
            },
        );

        $this->singleton(
            IdTokenMinter::class,
            fn(): IdTokenMinter => new IdTokenMinter(
                keyProvider: $this->resolve(KeyMaterialProviderInterface::class),
                algorithmPolicy: $this->resolve(SigningAlgorithmPolicy::class),
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
            // C-22 WP3: canonical repository.
            fn(): OidcClientLookup => new OidcClientLookup(
                $this->resolve(EntityTypeManager::class)->getRepository('oidc_client'),
            ),
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
                accessTokenIssuer: $this->resolve(AccessTokenIssuer::class),
                entityTypeManager: $this->resolve(EntityTypeManager::class),
                entityAccessHandler: $this->resolve(EntityAccessHandler::class),
                principalFactory: $this->resolve(\Waaseyaa\Access\AccountPrincipalFactoryInterface::class),
                claimResolver: $this->resolve(UserinfoClaimResolver::class),
                userInternalFields: $this->resolve(\Waaseyaa\Access\User\UserInternalFieldReaderInterface::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->seedOidcClientsFromConfig();
    }

    public function applicationMasterRekeyContributions(): iterable
    {
        $database = $this->resolve(DatabaseInterface::class);
        if (!$database instanceof DatabaseInterface) {
            throw new \LogicException('OIDC rekey composition requires the kernel database authority.');
        }
        $skew = $this->lifecycleDuration('maximum_clock_skew_seconds', 300);
        $maximumTokenLifetime = $this->lifecycleDuration('maximum_token_lifetime_seconds', 7_776_000);
        $signingRetention = $maximumTokenLifetime
            + $skew
            + $this->lifecycleDuration('jwks_cache_lifetime_seconds', 86_400)
            + $this->lifecycleDuration('propagation_margin_seconds', 300);

        yield new ApplicationMasterRekeyContribution(
            new OidcSigningKeyRekeyAdapter(
                $database,
                $this->legacyBridgeKey(ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION),
            ),
            [new ApplicationMasterPurposePolicy(
                ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
                'waaseyaa/oidc',
                ApplicationMasterPurposeStrategy::ReencryptCiphertext,
                $maximumTokenLifetime,
                $signingRetention,
                OidcSigningKeyRekeyAdapter::ID,
                'restore-predecessor-ciphertext',
            )],
        );
        yield new ApplicationMasterRekeyContribution(
            new OidcAccessTokenRekeyAdapter(
                $database,
                $this->legacyBridgeKey(ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION),
                $this->legacyBridgeKey(ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP),
            ),
            [
                new ApplicationMasterPurposePolicy(
                    ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                    'waaseyaa/oidc',
                    ApplicationMasterPurposeStrategy::ReencryptCiphertext,
                    AccessTokenIssuer::EXPIRY_SECONDS,
                    AccessTokenIssuer::EXPIRY_SECONDS + $skew,
                    OidcAccessTokenRekeyAdapter::ID,
                    'restore-predecessor-ciphertext-and-index',
                ),
                new ApplicationMasterPurposePolicy(
                    ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
                    'waaseyaa/oidc',
                    ApplicationMasterPurposeStrategy::RecomputeLookupIndex,
                    AccessTokenIssuer::EXPIRY_SECONDS,
                    AccessTokenIssuer::EXPIRY_SECONDS + $skew,
                    OidcAccessTokenRekeyAdapter::ID,
                    'restore-predecessor-ciphertext-and-index',
                ),
            ],
        );
        yield new ApplicationMasterRekeyContribution(
            new OidcRefreshTokenRekeyAdapter(
                $database,
                $this->legacyBridgeKey(ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION),
                $this->legacyBridgeKey(ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP),
            ),
            [
                new ApplicationMasterPurposePolicy(
                    ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
                    'waaseyaa/oidc',
                    ApplicationMasterPurposeStrategy::ReencryptCiphertext,
                    RefreshTokenIssuer::EXPIRY_SECONDS,
                    RefreshTokenIssuer::EXPIRY_SECONDS + $skew,
                    OidcRefreshTokenRekeyAdapter::ID,
                    'restore-predecessor-ciphertext-and-index',
                ),
                new ApplicationMasterPurposePolicy(
                    ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
                    'waaseyaa/oidc',
                    ApplicationMasterPurposeStrategy::RecomputeLookupIndex,
                    RefreshTokenIssuer::EXPIRY_SECONDS,
                    RefreshTokenIssuer::EXPIRY_SECONDS + $skew,
                    OidcRefreshTokenRekeyAdapter::ID,
                    'restore-predecessor-ciphertext-and-index',
                ),
            ],
        );
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
            // C-22 WP3: canonical repository.
            $repository = $this->resolve(EntityTypeManager::class)->getRepository('oidc_client');
        } catch (\Throwable) {
            return;
        }

        new OidcClientSeeder($repository)->seed($clients);
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

    private function lifecycleDuration(string $name, int $default): int
    {
        $value = $this->config['oidc']['signing_key_lifecycle'][$name] ?? $default;
        if (!is_int($value)) {
            throw new \RuntimeException(sprintf(
                'OIDC signing-key lifecycle setting "%s" must be an integer.',
                $name,
            ));
        }

        return $value;
    }

    /** @return array{ApplicationMasterKeyring|null, string|null} */
    private function runtimeCustody(string $purpose): array
    {
        $keyring = $this->resolveOptional(ApplicationMasterKeyring::class);
        if ($keyring instanceof ApplicationMasterKeyring
            && ($this->config['oidc']['accept_legacy_application_secret_material'] ?? false) !== true) {
            return [$keyring, null];
        }
        $applicationSecret = $this->resolve(ApplicationSecret::class);
        if (!$applicationSecret instanceof ApplicationSecret) {
            throw new \LogicException('OIDC legacy custody requires the application secret compatibility authority.');
        }

        return [
            $keyring instanceof ApplicationMasterKeyring ? $keyring : null,
            $applicationSecret->derive($purpose),
        ];
    }

    private function legacyBridgeKey(string $purpose): ?string
    {
        if (($this->config['oidc']['accept_legacy_application_secret_material'] ?? false) !== true) {
            return null;
        }
        $applicationSecret = $this->resolve(ApplicationSecret::class);
        if (!$applicationSecret instanceof ApplicationSecret) {
            throw new \LogicException('OIDC legacy rekey bridge requires the application secret compatibility authority.');
        }

        return $applicationSecret->derive($purpose);
    }
}
