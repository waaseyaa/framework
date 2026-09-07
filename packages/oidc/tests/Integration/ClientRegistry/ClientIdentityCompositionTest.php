<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\ClientRegistry;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Oidc\Authorize\AuthorizationRequestValidator;
use Waaseyaa\Oidc\Authorize\AuthorizeController;
use Waaseyaa\Oidc\ClientRegistry\OidcClientLookup;
use Waaseyaa\Oidc\Consent\ConsentRepository;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Oidc\Exception\AmbiguousClientIdException;
use Waaseyaa\Oidc\Keys\OpenSslKeyFactory;
use Waaseyaa\Oidc\Keys\RsaSigningKeySigner;
use Waaseyaa\Oidc\Keys\SigningKey;
use Waaseyaa\Oidc\Keys\SigningKeySignerInterface;
use Waaseyaa\Oidc\Repository\AuthorizationCode;
use Waaseyaa\Oidc\Repository\AuthorizationCodeRepositoryInterface;
use Waaseyaa\Oidc\Revoke\RevocationController;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\IdTokenMinter;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;
use Waaseyaa\Oidc\Token\PkceVerifier;
use Waaseyaa\Oidc\Token\RefreshTokenGrantHandler;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;
use Waaseyaa\Oidc\Token\TokenController;
use Waaseyaa\Oidc\Token\TokenRequestValidator;

/**
 * #2766 — composition proof that authorize, token (authorization_code AND
 * refresh_token grants), and revoke all resolve `client_id` through the same
 * shared {@see OidcClientLookup}, and that every one of those entry points
 * fails closed — never selecting an arbitrary row — the moment `client_id`
 * is not a unique registry identity.
 *
 * This deliberately builds the `oidc_client` table WITHOUT the
 * `2026_09_06_000009_oidc_client_id_unique_key` migration (mirroring
 * {@see OidcClientLookupTest}), the same way a database that has not yet run
 * `bin/waaseyaa migrate` after upgrading would look. On such a database, two
 * rows can share a `client_id` (historical duplicate, or a race — see
 * {@see OidcClientSeederConcurrencyTest} for the write-side race). Every
 * pre-auth control built on top of {@see OidcClientLookup} must refuse to
 * authenticate against either row rather than silently picking one
 * (`AmbiguousClientIdException`, not a 200/302/access token).
 */
#[CoversNothing]
final class ClientIdentityCompositionTest extends TestCase
{
    private const REDIRECT_URI = 'https://app.example/callback';

    private EntityRepository $clientRepository;
    private OidcClientLookup $clientLookup;
    private DBALDatabase $tokenDb;
    private AccessTokenIssuer $accessTokenIssuer;
    private RefreshTokenIssuer $refreshTokenIssuer;
    private string $privateKeyPem;
    private string $publicKeyPem;

    protected function setUp(): void
    {
        $entityDb = DBALDatabase::createSqlite();

        $entityType = new EntityType(
            id: 'oidc_client',
            label: 'OIDC Client',
            class: OidcClient::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $entityDb);
        $schemaHandler->ensureTable();
        $schemaHandler->addFieldColumns([
            'client_id' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'is_confidential' => ['type' => 'int', 'not null' => true, 'default' => 0],
            'client_secret_hash' => ['type' => 'varchar', 'length' => 255, 'not null' => false],
        ]);

        $this->clientRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($entityDb)),
            new EventDispatcher(),
            database: $entityDb,
        );

        // Historical duplicate: two rows sharing client_id, exactly the
        // reproduction shape from #2766 -- different redirect authority and
        // different confidentiality/secret, so an arbitrary pick would be
        // authenticating against the wrong redirect URI or auth requirement.
        $first = $this->clientRepository->create([
            'client_id' => 'ambiguous-client',
            'name' => 'First (public)',
            'redirect_uris' => [self::REDIRECT_URI],
            'scopes' => ['openid'],
            'grant_types' => ['authorization_code'],
            'is_confidential' => false,
        ]);
        $this->clientRepository->save($first);

        $secondHash = password_hash('some-secret', PASSWORD_BCRYPT);
        self::assertIsString($secondHash);
        $second = $this->clientRepository->create([
            'client_id' => 'ambiguous-client',
            'name' => 'Second (confidential impersonator)',
            'redirect_uris' => ['https://evil.example/cb'],
            'scopes' => ['openid'],
            'grant_types' => ['authorization_code'],
            'is_confidential' => true,
            'client_secret_hash' => $secondHash,
        ]);
        $this->clientRepository->save($second);

        // Single shared lookup instance -- mirrors OidcServiceProvider wiring
        // exactly one OidcClientLookup resolved for authorize, token, and
        // revoke (see packages/oidc/src/OidcServiceProvider.php).
        $this->clientLookup = new OidcClientLookup($this->clientRepository);

        $this->tokenDb = DBALDatabase::createSqlite();
        OidcSchema::installTokenStorage($this->tokenDb);
        $this->accessTokenIssuer = new AccessTokenIssuer($this->tokenDb, str_repeat('a', 32), str_repeat('b', 32));
        $this->refreshTokenIssuer = new RefreshTokenIssuer($this->tokenDb, str_repeat('c', 32), str_repeat('d', 32));

        $keyPair = new OpenSslKeyFactory()->generateRsaKeyPair();
        $this->privateKeyPem = $keyPair['private'];
        $this->publicKeyPem = $keyPair['public'];
    }

    #[Test]
    public function authorizeRefusesAnAmbiguousClientIdInsteadOfPickingARow(): void
    {
        $controller = new AuthorizeController(
            clientLookup: $this->clientLookup,
            validator: new AuthorizationRequestValidator(),
            codeRepository: $this->refusingCodeRepository(),
            consentRepository: $this->noConsentRepository(),
        );

        $request = Request::create('/authorize', 'GET', [
            'client_id' => 'ambiguous-client',
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'openid',
            'state' => 'xyz',
            'code_challenge' => 'a-challenge',
            'code_challenge_method' => 'S256',
        ]);
        $request->attributes->set('_account', $this->authenticatedAccount());

        try {
            ($controller)($request);
            self::fail('Expected AmbiguousClientIdException; authorize must never redirect for an ambiguous client_id.');
        } catch (AmbiguousClientIdException $exception) {
            self::assertSame('ambiguous-client', $exception->clientId);
            self::assertCount(2, $exception->matchingIds);
        }
    }

    #[Test]
    public function tokenAuthorizationCodeGrantRefusesAnAmbiguousClientIdInsteadOfPickingARow(): void
    {
        $controller = $this->tokenController();

        $request = Request::create('/token', 'POST', [
            'grant_type' => 'authorization_code',
            // The code need not exist: findByClientId() is resolved before
            // the authorization-code repository is ever consulted, so an
            // ambiguous client_id must fail closed regardless of the rest
            // of the request's validity.
            'code' => 'irrelevant-code',
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => str_repeat('v', 43),
            'client_id' => 'ambiguous-client',
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        try {
            ($controller)($request);
            self::fail('Expected AmbiguousClientIdException; the authorization_code grant must never mint tokens for an ambiguous client_id.');
        } catch (AmbiguousClientIdException $exception) {
            self::assertSame('ambiguous-client', $exception->clientId);
            self::assertCount(2, $exception->matchingIds);
        }
    }

    #[Test]
    public function tokenRefreshGrantRefusesAnAmbiguousClientIdInsteadOfPickingARow(): void
    {
        $controller = $this->tokenController();

        $request = Request::create('/token', 'POST', [
            'grant_type' => 'refresh_token',
            // Same reasoning as the authorization_code case above: the
            // client lookup runs before the refresh token is ever read.
            'refresh_token' => 'irrelevant-refresh-token',
            'client_id' => 'ambiguous-client',
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        try {
            ($controller)($request);
            self::fail('Expected AmbiguousClientIdException; the refresh_token grant must never mint tokens for an ambiguous client_id.');
        } catch (AmbiguousClientIdException $exception) {
            self::assertSame('ambiguous-client', $exception->clientId);
            self::assertCount(2, $exception->matchingIds);
        }
    }

    #[Test]
    public function revokeRefusesAnAmbiguousClientIdInsteadOfPickingARow(): void
    {
        $controller = new RevocationController(
            clientLookup: $this->clientLookup,
            accessTokenIssuer: $this->accessTokenIssuer,
            refreshTokenIssuer: $this->refreshTokenIssuer,
        );

        $request = Request::create('/oidc/revoke', 'POST', [
            'client_id' => 'ambiguous-client',
            'token' => 'irrelevant-token',
        ]);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        try {
            ($controller)($request);
            self::fail('Expected AmbiguousClientIdException; revoke must never act as an ambiguous client_id against either row.');
        } catch (AmbiguousClientIdException $exception) {
            self::assertSame('ambiguous-client', $exception->clientId);
            self::assertCount(2, $exception->matchingIds);
        }
    }

    private function tokenController(): TokenController
    {
        $accessTokenIssuer = $this->accessTokenIssuer;
        $refreshTokenIssuer = $this->refreshTokenIssuer;

        return new TokenController(
            clientLookup: $this->clientLookup,
            validator: new TokenRequestValidator(),
            pkceVerifier: new PkceVerifier(),
            codeRepository: $this->refusingCodeRepository(),
            idTokenMinter: new IdTokenMinter($this->keyProvider()),
            accessTokenIssuer: $accessTokenIssuer,
            refreshTokenIssuer: $refreshTokenIssuer,
            refreshGrantHandler: new RefreshTokenGrantHandler(
                $refreshTokenIssuer,
                $accessTokenIssuer,
                new IdTokenMinter($this->keyProvider()),
            ),
            issuer: 'https://idp.example',
            clock: fn(): DateTimeImmutable => new DateTimeImmutable('2026-09-06T12:00:00Z'),
        );
    }

    private function authenticatedAccount(): \Waaseyaa\Access\AccountInterface
    {
        return new class implements \Waaseyaa\Access\AccountInterface {
            public function id(): int|string
            {
                return 42;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function refusingCodeRepository(): AuthorizationCodeRepositoryInterface
    {
        return new class implements AuthorizationCodeRepositoryInterface {
            public function issue(
                string $clientId,
                \Waaseyaa\Access\AccountInterface $account,
                string $redirectUri,
                array $scopes,
                string $codeChallenge,
                string $codeChallengeMethod,
                ?string $nonce = null,
            ): AuthorizationCode {
                throw new \RuntimeException(
                    'issue() must not be reached: the ambiguous client_id must be refused before code issuance.',
                );
            }

            public function consume(string $code): ?AuthorizationCode
            {
                throw new \RuntimeException(
                    'consume() must not be reached: the ambiguous client_id must be refused before code lookup.',
                );
            }

            public function purgeExpired(): int
            {
                return 0;
            }
        };
    }

    private function noConsentRepository(): ConsentRepository
    {
        $db = DBALDatabase::createSqlite();
        $migration = require dirname(__DIR__, 3) . '/migrations/2026_05_25_000004_oidc_user_consent_schema.php';
        $migration->up(new \Waaseyaa\Foundation\Migration\SchemaBuilder($db->getConnection()));

        return new ConsentRepository($db);
    }

    private function keyProvider(): KeyMaterialProviderInterface
    {
        return new class ($this->privateKeyPem, $this->publicKeyPem) implements KeyMaterialProviderInterface {
            public function __construct(private string $private_pem, private string $public_pem) {}

            public function currentKey(): SigningKey
            {
                return new SigningKey('test-kid', 'RS256', $this->public_pem);
            }

            public function currentSigner(): SigningKeySignerInterface
            {
                return RsaSigningKeySigner::fromPrivatePem($this->currentKey(), $this->private_pem);
            }

            public function allActive(): array
            {
                return [$this->currentKey()];
            }
        };
    }
}
