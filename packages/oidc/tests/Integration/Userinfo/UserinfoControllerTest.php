<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Userinfo;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\AccessTokenPair;
use Waaseyaa\Oidc\Userinfo\UserinfoClaimResolver;
use Waaseyaa\Oidc\Userinfo\UserinfoController;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

/**
 * Regression test for C-9: /oidc/userinfo must authenticate the OPAQUE access
 * token (the value clients actually receive from the token endpoint), not a JWT.
 *
 * Before the fix the controller fed the bearer to IdTokenMinter::verifyAndDecode,
 * which rejects any non-three-part string -> every real (opaque) access token 401s,
 * and the jti-keyed revocation check was unreachable dead code.
 */
#[CoversClass(UserinfoController::class)]
final class UserinfoControllerTest extends TestCase
{
    private const ACCOUNT_ID = 42;

    private DBALDatabase $tokenDb;
    private AccessTokenIssuer $accessTokenIssuer;
    private EntityRepository $userRepository;

    protected function setUp(): void
    {
        // Shared in-memory connection for the oidc_access_token table so the
        // issuer and the controller see the same rows.
        $this->tokenDb = DBALDatabase::createSqlite();
        $this->accessTokenIssuer = new AccessTokenIssuer($this->tokenDb, str_repeat('a', 32), str_repeat('b', 32));

        // User entity storage (separate in-memory DB is fine; the controller
        // resolves it via the EntityTypeManager).
        $userDb = DBALDatabase::createSqlite();
        $userEntityType = new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        $schemaHandler = new SqlSchemaHandler($userEntityType, $userDb);
        $schemaHandler->ensureTable();
        $schemaHandler->addFieldColumns([
            'name' => ['type' => 'varchar', 'length' => 255, 'not null' => false],
            'mail' => ['type' => 'varchar', 'length' => 255, 'not null' => false],
            'email_verified' => ['type' => 'int', 'not null' => true, 'default' => 0],
            'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
            'created' => ['type' => 'int', 'not null' => false],
        ]);
        // C-22 WP4: the sole persistence engine — no separate storage read path.
        $this->userRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $userEntityType,
            new SqlStorageDriver(new SingleConnectionResolver($userDb), 'uid'),
            new EventDispatcher(),
            database: $userDb,
        );

        $user = new User([
            'uid' => self::ACCOUNT_ID,
            'name' => 'Subject Forty-Two',
            'mail' => 'subject42@example.test',
            'email_verified' => true,
        ]);
        $user->enforceIsNew();
        $this->userRepository->save($user);
    }

    #[Test]
    public function validOpaqueTokenReturns200WithClaims(): void
    {
        $pair = $this->issueToken();

        $response = ($this->controller())($this->bearerRequest($pair->token));

        self::assertSame(200, $response->getStatusCode(), 'A valid opaque access token must authenticate.');
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame((string) self::ACCOUNT_ID, $payload['sub']);
        self::assertSame('subject42@example.test', $payload['email']);
        // Scope was 'openid email profile' -> email/name claims resolvable.
        self::assertSame('Subject Forty-Two', $payload['name']);
    }

    #[Test]
    public function inactive_non_admin_subject_does_not_receive_the_protected_name_claim(): void
    {
        $user = $this->userRepository->find((string) self::ACCOUNT_ID);
        self::assertInstanceOf(User::class, $user);
        $user->set('status', 0);
        $this->userRepository->save($user);
        $pair = $this->issueToken();

        $response = ($this->controller())($this->bearerRequest($pair->token));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey(
            'name',
            $payload,
            'Userinfo released the Protected name of an inactive non-admin subject.',
        );
        self::assertArrayNotHasKey('preferred_username', $payload);
        self::assertSame('subject42@example.test', $payload['email']);
    }

    #[Test]
    public function active_subject_without_profile_permission_does_not_receive_profile_claims(): void
    {
        $pair = $this->issueToken();

        $response = ($this->controller(profileAccess: false))($this->bearerRequest($pair->token));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('name', $payload);
        self::assertArrayNotHasKey('preferred_username', $payload);
        self::assertSame('subject42@example.test', $payload['email']);
    }

    #[Test]
    public function inactive_admin_subject_receives_the_protected_name_claim(): void
    {
        $user = $this->userRepository->find((string) self::ACCOUNT_ID);
        self::assertInstanceOf(User::class, $user);
        $user->set('status', 0);
        $this->userRepository->save($user);
        $pair = $this->issueToken();

        $response = ($this->controller(admin: true))($this->bearerRequest($pair->token));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('Subject Forty-Two', $payload['name']);
    }

    #[Test]
    public function revokedTokenReturns401(): void
    {
        $pair = $this->issueToken();
        $this->accessTokenIssuer->revoke($pair->jti, new DateTimeImmutable('@2000000100'));

        $response = ($this->controller())($this->bearerRequest($pair->token));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_token', $this->jsonError($response));
    }

    #[Test]
    public function expiredTokenReturns401(): void
    {
        // Issue with a 'now' one hour + a day in the past so expires_at < time().
        $past = new DateTimeImmutable('-2 days');
        $pair = $this->accessTokenIssuer->issue(
            'spa-1',
            (string) self::ACCOUNT_ID,
            ['openid', 'email', 'profile'],
            $past,
        );

        $response = ($this->controller())($this->bearerRequest($pair->token));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_token', $this->jsonError($response));
    }

    #[Test]
    public function garbageBearerReturns401(): void
    {
        $response = ($this->controller())($this->bearerRequest('not-a-real-token-value'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_token', $this->jsonError($response));
    }

    #[Test]
    public function missingAuthorizationHeaderReturns401(): void
    {
        $response = ($this->controller())(Request::create('/oidc/userinfo', 'GET'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_token', $this->jsonError($response));
    }

    private function issueToken(): AccessTokenPair
    {
        return $this->accessTokenIssuer->issue(
            'spa-1',
            (string) self::ACCOUNT_ID,
            ['openid', 'email', 'profile'],
            new DateTimeImmutable(),
        );
    }

    private function controller(bool $admin = false, bool $profileAccess = true): UserinfoController
    {
        $entityTypeManager = $this->createMock(EntityTypeManager::class);
        $entityTypeManager->method('getRepository')->with('user')->willReturn($this->userRepository);
        $principalFactory = $this->createMock(AccountPrincipalFactoryInterface::class);
        $principalFactory->method('fromAccount')->willReturn(new AuthorizationPrincipal(
            accountId: self::ACCOUNT_ID,
            authenticated: true,
            roles: [],
            permissions: $admin ? ['administer users'] : ($profileAccess ? ['access user profiles'] : []),
            claimsGeneration: $admin ? 'admin-test' : ($profileAccess ? 'profile-test' : 'subject-test'),
        ));

        return new UserinfoController(
            accessTokenIssuer: $this->accessTokenIssuer,
            entityTypeManager: $entityTypeManager,
            entityAccessHandler: new EntityAccessHandler([new UserAccessPolicy()]),
            principalFactory: $principalFactory,
            claimResolver: new UserinfoClaimResolver(),
            userInternalFields: new UserInternalFieldReaderFixture(),
        );
    }

    private function bearerRequest(string $token): Request
    {
        $request = Request::create('/oidc/userinfo', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    private function jsonError(Response $response): string
    {
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);

        return (string) ($payload['error'] ?? '');
    }
}
