<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Revoke;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
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
use Waaseyaa\Oidc\ClientRegistry\OidcClientLookup;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Oidc\Revoke\RevocationController;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;

#[CoversClass(RevocationController::class)]
final class RevocationControllerTest extends TestCase
{
    private EntityRepository $repository;
    private DBALDatabase $tokenDb;
    private AccessTokenIssuer $accessTokenIssuer;
    private RefreshTokenIssuer $refreshTokenIssuer;

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

        $dispatcher = new EventDispatcher();
        $this->repository = new EntityRepository(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($entityDb)),
            $dispatcher,
            database: $entityDb,
        );

        $this->tokenDb = DBALDatabase::createSqlite();
        $this->accessTokenIssuer = new AccessTokenIssuer($this->tokenDb, str_repeat('a', 32), str_repeat('b', 32));
        $this->refreshTokenIssuer = new RefreshTokenIssuer($this->tokenDb, str_repeat('c', 32), str_repeat('d', 32));
    }

    // -------------------------------------------------------------------------
    // Happy path: a client can revoke its OWN tokens
    // -------------------------------------------------------------------------

    #[Test]
    public function clientCanRevokeItsOwnRefreshToken(): void
    {
        $this->seedPublicClient('client-a');

        $now = new DateTimeImmutable('2026-06-01T00:00:00Z');
        $atPair = $this->accessTokenIssuer->issue('client-a', 'user-1', ['openid'], $now);
        $rtRecord = $this->refreshTokenIssuer->issue(
            accessTokenJti: $atPair->jti,
            clientId: 'client-a',
            accountId: 'user-1',
            scopes: ['openid'],
            authTime: $now->getTimestamp(),
            now: $now,
        );

        $controller = $this->controller(['client-a']);

        $response = ($controller)($this->postRevoke(
            clientId: 'client-a',
            token: $rtRecord->token,
        ));

        self::assertSame(200, $response->getStatusCode());

        $afterRevoke = $this->refreshTokenIssuer->findByJti($rtRecord->jti);
        self::assertNotNull($afterRevoke);
        self::assertTrue($afterRevoke->isRevoked(), 'Client A\'s own refresh token must be revoked.');
    }

    #[Test]
    public function clientCanRevokeItsOwnAccessToken(): void
    {
        $this->seedPublicClient('client-a');

        $now = new DateTimeImmutable('2026-06-01T00:00:00Z');
        $atPair = $this->accessTokenIssuer->issue('client-a', 'user-1', ['openid'], $now);

        $controller = $this->controller(['client-a']);

        $response = ($controller)($this->postRevoke(
            clientId: 'client-a',
            token: $atPair->token,
            hint: 'access_token',
        ));

        self::assertSame(200, $response->getStatusCode());

        $afterRevoke = $this->accessTokenIssuer->findByJti($atPair->jti);
        self::assertNotNull($afterRevoke);
        self::assertNotNull($afterRevoke['revoked_at'], 'Client A\'s own access token must be revoked.');
    }

    // -------------------------------------------------------------------------
    // Security: cross-client revocation must be blocked (RFC 7009 §2.1)
    // -------------------------------------------------------------------------

    #[Test]
    public function clientBCannotRevokeClientAsRefreshToken(): void
    {
        $this->seedPublicClient('client-a');
        $this->seedPublicClient('client-b');

        $now = new DateTimeImmutable('2026-06-01T00:00:00Z');
        $atPair = $this->accessTokenIssuer->issue('client-a', 'user-1', ['openid'], $now);
        $rtRecord = $this->refreshTokenIssuer->issue(
            accessTokenJti: $atPair->jti,
            clientId: 'client-a',
            accountId: 'user-1',
            scopes: ['openid'],
            authTime: $now->getTimestamp(),
            now: $now,
        );

        // Authenticate as client-b but submit client-a's refresh token
        $controller = $this->controller(['client-a', 'client-b']);

        $response = ($controller)($this->postRevoke(
            clientId: 'client-b',
            token: $rtRecord->token,
            hint: 'refresh_token',
        ));

        // RFC 7009 §2.2: response must be 200 (no enumeration oracle)
        self::assertSame(200, $response->getStatusCode());

        // But the token must NOT have been revoked
        $afterRevoke = $this->refreshTokenIssuer->findByJti($rtRecord->jti);
        self::assertNotNull($afterRevoke);
        self::assertFalse($afterRevoke->isRevoked(), 'Client B must NOT be able to revoke Client A\'s refresh token.');
    }

    #[Test]
    public function clientBCannotRevokeClientAsAccessToken(): void
    {
        $this->seedPublicClient('client-a');
        $this->seedPublicClient('client-b');

        $now = new DateTimeImmutable('2026-06-01T00:00:00Z');
        $atPair = $this->accessTokenIssuer->issue('client-a', 'user-1', ['openid'], $now);
        // Also issue a paired refresh token to verify cascade does NOT happen
        $rtRecord = $this->refreshTokenIssuer->issue(
            accessTokenJti: $atPair->jti,
            clientId: 'client-a',
            accountId: 'user-1',
            scopes: ['openid'],
            authTime: $now->getTimestamp(),
            now: $now,
        );

        // Authenticate as client-b but submit client-a's access token
        $controller = $this->controller(['client-a', 'client-b']);

        $response = ($controller)($this->postRevoke(
            clientId: 'client-b',
            token: $atPair->token,
            hint: 'access_token',
        ));

        // RFC 7009 §2.2: response must be 200 (no enumeration oracle)
        self::assertSame(200, $response->getStatusCode());

        // Access token must NOT have been revoked
        $afterRevokeAt = $this->accessTokenIssuer->findByJti($atPair->jti);
        self::assertNotNull($afterRevokeAt);
        self::assertNull($afterRevokeAt['revoked_at'], 'Client B must NOT be able to revoke Client A\'s access token.');

        // Paired refresh token must NOT have been cascade-revoked
        $afterRevokeRt = $this->refreshTokenIssuer->findByJti($rtRecord->jti);
        self::assertNotNull($afterRevokeRt);
        self::assertFalse($afterRevokeRt->isRevoked(), 'Cascade revoke of paired refresh token must NOT happen when access token belongs to a different client.');
    }

    #[Test]
    public function clientBCannotRevokeClientAsRefreshTokenWithoutHint(): void
    {
        // No-hint path: tries refresh first, then access.
        // Ensure cross-client is blocked in the no-hint path too.
        $this->seedPublicClient('client-a');
        $this->seedPublicClient('client-b');

        $now = new DateTimeImmutable('2026-06-01T00:00:00Z');
        $atPair = $this->accessTokenIssuer->issue('client-a', 'user-1', ['openid'], $now);
        $rtRecord = $this->refreshTokenIssuer->issue(
            accessTokenJti: $atPair->jti,
            clientId: 'client-a',
            accountId: 'user-1',
            scopes: ['openid'],
            authTime: $now->getTimestamp(),
            now: $now,
        );

        $controller = $this->controller(['client-a', 'client-b']);

        // No token_type_hint
        $response = ($controller)($this->postRevoke(
            clientId: 'client-b',
            token: $rtRecord->token,
        ));

        self::assertSame(200, $response->getStatusCode());

        $afterRevoke = $this->refreshTokenIssuer->findByJti($rtRecord->jti);
        self::assertNotNull($afterRevoke);
        self::assertFalse($afterRevoke->isRevoked(), 'No-hint path: Client B must NOT revoke Client A\'s refresh token.');
    }

    // -------------------------------------------------------------------------
    // Infrastructure: standard 401/405 paths still work
    // -------------------------------------------------------------------------

    #[Test]
    public function rejectsNonPostWith405(): void
    {
        $controller = $this->controller([]);
        $response = ($controller)(Request::create('/oidc/revoke', 'GET'));

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function unknownClientReturns401(): void
    {
        $controller = $this->controller([]);
        $response = ($controller)($this->postRevoke(clientId: 'no-such-client', token: 'irrelevant'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function missingTokenReturns200NoOp(): void
    {
        $this->seedPublicClient('client-a');
        $controller = $this->controller(['client-a']);

        $request = Request::create('/oidc/revoke', 'POST', ['client_id' => 'client-a']);
        $response = ($controller)($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param list<string> $clientIds */
    private function controller(array $clientIds): RevocationController
    {
        foreach ($clientIds as $clientId) {
            // Ensure the client is seeded (idempotent — setUp seeds nothing by default)
        }

        return new RevocationController(
            clientLookup: new OidcClientLookup($this->repository),
            accessTokenIssuer: $this->accessTokenIssuer,
            refreshTokenIssuer: $this->refreshTokenIssuer,
        );
    }

    private function seedPublicClient(string $clientId): void
    {
        $existing = (new OidcClientLookup($this->repository))->findByClientId($clientId);
        if ($existing !== null) {
            return;
        }

        $this->repository->save(
            $this->repository->create([
                'client_id' => $clientId,
                'name' => $clientId,
                'redirect_uris' => ['https://example.com/callback'],
                'scopes' => ['openid'],
                'grant_types' => ['authorization_code'],
                'is_confidential' => false,
            ]),
        );
    }

    private function postRevoke(string $clientId, string $token, ?string $hint = null): Request
    {
        $params = [
            'client_id' => $clientId,
            'token' => $token,
        ];

        if ($hint !== null) {
            $params['token_type_hint'] = $hint;
        }

        $request = Request::create('/oidc/revoke', 'POST', $params);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        return $request;
    }
}
