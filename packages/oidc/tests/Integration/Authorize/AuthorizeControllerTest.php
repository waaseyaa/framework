<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Authorize;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Oidc\Authorize\AuthorizationRequestValidator;
use Waaseyaa\Oidc\Authorize\AuthorizeController;
use Waaseyaa\Oidc\ClientRegistry\OidcClientLookup;
use Waaseyaa\Oidc\Consent\ConsentRepository;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Oidc\Repository\AuthorizationCode;
use Waaseyaa\Oidc\Repository\AuthorizationCodeRepositoryInterface;

#[CoversClass(AuthorizeController::class)]
final class AuthorizeControllerTest extends TestCase
{
    private SqlEntityStorage $storage;
    private EntityRepository $repository;
    private FakeCodeRepository $codeRepository;
    private AuthorizeController $controller;
    private ConsentRepository $consentRepository;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();

        $entityType = new EntityType(
            id: 'oidc_client',
            label: 'OIDC Client',
            class: OidcClient::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $database);
        $schemaHandler->ensureTable();
        $schemaHandler->addFieldColumns([
            'client_id' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'is_confidential' => ['type' => 'int', 'not null' => true, 'default' => 0],
            'client_secret_hash' => ['type' => 'varchar', 'length' => 255, 'not null' => false],
        ]);

        $dispatcher = new EventDispatcher();
        $this->storage = new SqlEntityStorage($entityType, $database, $dispatcher);
        $this->repository = new EntityRepository(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($database)),
            $dispatcher,
            database: $database,
        );

        $client = $this->storage->create([
            'client_id' => 'minoo-web',
            'name' => 'Minoo',
            'redirect_uris' => ['https://minoo.test/callback'],
            'scopes' => ['openid', 'profile', 'email'],
            'grant_types' => ['authorization_code'],
        ]);
        $this->storage->save($client);

        $this->codeRepository = new FakeCodeRepository();

        $this->consentRepository = new ConsentRepository(DBALDatabase::createSqlite());
        // Pre-seed consent so authenticated tests reach the code-issue path.
        $this->consentRepository->record('42', 'minoo-web', ['openid', 'profile']);

        $this->controller = new AuthorizeController(
            clientLookup: new OidcClientLookup($this->repository),
            validator: new AuthorizationRequestValidator(),
            codeRepository: $this->codeRepository,
            consentRepository: $this->consentRepository,
            loginPath: '/login',
        );
    }

    public function testAnonymousAccountRedirectsToLoginWithReturnTo(): void
    {
        $request = $this->makeRequest($this->validQuery(), authenticated: false);

        $response = ($this->controller)($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith('/login?return_to=', $location);
        // The return_to value should be URL-encoded.
        $this->assertStringContainsString(rawurlencode('/authorize?'), $location);
        $this->assertStringContainsString(rawurlencode('client_id=minoo-web'), $location);
    }

    public function testAnonymousRedirectPreservesPreExistingLoginPathQueryString(): void
    {
        // Regression for #1290 — login_path with a pre-existing query string
        // produced `/login?foo=bar?return_to=...` (malformed). The controller
        // must use `&` as the separator when the path already contains `?`.
        $controller = new AuthorizeController(
            clientLookup: new OidcClientLookup($this->repository),
            validator: new AuthorizationRequestValidator(),
            codeRepository: $this->codeRepository,
            consentRepository: $this->consentRepository,
            loginPath: '/login?foo=bar',
        );

        $request = $this->makeRequest($this->validQuery(), authenticated: false);

        $response = $controller($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith('/login?foo=bar&return_to=', $location);
        $this->assertStringNotContainsString('?return_to=', substr($location, strlen('/login?foo=bar')));
    }

    public function testMissingAccountAttributeReturns500(): void
    {
        $request = Request::create('/authorize', 'GET', $this->validQuery());

        $response = ($this->controller)($request);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testMissingClientIdReturnsDirectError(): void
    {
        $query = $this->validQuery();
        unset($query['client_id']);

        $response = ($this->controller)($this->makeRequest($query));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('invalid_request', (string) $response->getContent());
    }

    public function testUnknownClientIdReturnsDirectError(): void
    {
        $query = $this->validQuery();
        $query['client_id'] = 'does-not-exist';

        $response = ($this->controller)($this->makeRequest($query));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNotInstanceOf(RedirectResponse::class, $response);
    }

    public function testUnregisteredRedirectUriReturnsDirectError(): void
    {
        $query = $this->validQuery();
        $query['redirect_uri'] = 'https://evil.test/cb';

        $response = ($this->controller)($this->makeRequest($query));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNotInstanceOf(RedirectResponse::class, $response);
    }

    public function testRedirectableValidationErrorRedirectsToClientWithError(): void
    {
        $query = $this->validQuery();
        $query['response_type'] = 'token';

        $response = ($this->controller)($this->makeRequest($query));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location') ?? '';
        $this->assertStringStartsWith('https://minoo.test/callback?', $location);
        $this->assertStringContainsString('error=unsupported_response_type', $location);
        $this->assertStringContainsString('state=xyz-state', $location);
    }

    public function testValidRequestIssuesCodeAndRedirects(): void
    {
        $response = ($this->controller)($this->makeRequest($this->validQuery()));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        $location = $response->headers->get('Location') ?? '';
        $this->assertStringStartsWith('https://minoo.test/callback?', $location);
        $this->assertStringContainsString('code=issued-test-code', $location);
        $this->assertStringContainsString('state=xyz-state', $location);

        $this->assertSame('minoo-web', $this->codeRepository->lastClientId);
        $this->assertSame('https://minoo.test/callback', $this->codeRepository->lastRedirectUri);
        $this->assertSame(['openid', 'profile'], $this->codeRepository->lastScopes);
        $this->assertSame('a-challenge', $this->codeRepository->lastCodeChallenge);
        $this->assertSame('S256', $this->codeRepository->lastCodeChallengeMethod);
        $this->assertNull($this->codeRepository->lastNonce, 'nonce omitted in /authorize query must reach repo as null');
    }

    public function testNonceFromQueryIsPassedToRepository(): void
    {
        $query = $this->validQuery();
        $query['nonce'] = 'n-0S6_WzA2Mj';

        ($this->controller)($this->makeRequest($query));

        $this->assertSame('n-0S6_WzA2Mj', $this->codeRepository->lastNonce);
    }

    public function testValidRequestWithoutStateOmitsStateInRedirect(): void
    {
        $query = $this->validQuery();
        unset($query['state']);

        $response = ($this->controller)($this->makeRequest($query));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $location = $response->headers->get('Location') ?? '';
        $this->assertStringNotContainsString('state=', $location);
        $this->assertStringContainsString('code=issued-test-code', $location);
    }

    public function testRedirectUriWithExistingQueryStringUsesAmpersandSeparator(): void
    {
        // Register a client whose redirect_uri already has a query string.
        $client = $this->storage->create([
            'client_id' => 'fancy-client',
            'name' => 'Fancy',
            'redirect_uris' => ['https://fancy.test/cb?src=oidc'],
            'scopes' => ['openid'],
            'grant_types' => ['authorization_code'],
        ]);
        $this->storage->save($client);

        // Seed consent for fancy-client with scope openid
        $this->consentRepository->record('42', 'fancy-client', ['openid']);

        $query = $this->validQuery();
        $query['client_id'] = 'fancy-client';
        $query['redirect_uri'] = 'https://fancy.test/cb?src=oidc';
        $query['scope'] = 'openid';

        $response = ($this->controller)($this->makeRequest($query));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $location = $response->headers->get('Location') ?? '';
        $this->assertStringStartsWith('https://fancy.test/cb?src=oidc&', $location);
        $this->assertStringContainsString('code=issued-test-code', $location);
    }

    public function testAuthenticatedAccountIdPassedToRepository(): void
    {
        $request = $this->makeRequest($this->validQuery(), authenticated: true);

        ($this->controller)($request);

        $this->assertSame(42, $this->codeRepository->lastAccountId);
    }

    /**
     * @param array<string, string> $query
     */
    private function makeRequest(array $query, bool $authenticated = true): Request
    {
        $request = Request::create('/authorize', 'GET', $query);

        $account = new class ($authenticated) implements AccountInterface {
            public function __construct(private bool $authenticated)
            {
            }

            public function id(): int|string
            {
                return $this->authenticated ? 42 : 0;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return $this->authenticated ? ['authenticated'] : ['anonymous'];
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }
        };

        $request->attributes->set('_account', $account);

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private function validQuery(): array
    {
        return [
            'client_id' => 'minoo-web',
            'redirect_uri' => 'https://minoo.test/callback',
            'response_type' => 'code',
            'scope' => 'openid profile',
            'state' => 'xyz-state',
            'code_challenge' => 'a-challenge',
            'code_challenge_method' => 'S256',
        ];
    }
}

/**
 * Fake repository that records the most recent issue() call and returns a
 * deterministic AuthorizationCode so the controller's redirect can be asserted.
 */
final class FakeCodeRepository implements AuthorizationCodeRepositoryInterface
{
    public ?string $lastClientId = null;
    public int|string|null $lastAccountId = null;
    public ?string $lastRedirectUri = null;
    /** @var list<string>|null */
    public ?array $lastScopes = null;
    public ?string $lastCodeChallenge = null;
    public ?string $lastCodeChallengeMethod = null;
    public ?string $lastNonce = null;

    public function issue(
        string $clientId,
        AccountInterface $account,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        ?string $nonce = null,
    ): AuthorizationCode {
        $this->lastClientId = $clientId;
        $this->lastAccountId = $account->id();
        $this->lastRedirectUri = $redirectUri;
        $this->lastScopes = $scopes;
        $this->lastCodeChallenge = $codeChallenge;
        $this->lastCodeChallengeMethod = $codeChallengeMethod;
        $this->lastNonce = $nonce;

        return new AuthorizationCode(
            code: 'issued-test-code',
            clientId: $clientId,
            accountId: (string) $account->id(),
            redirectUri: $redirectUri,
            scopes: $scopes,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
            issuedAt: 1000,
            expiresAt: 1060,
            nonce: $nonce,
        );
    }

    public function consume(string $code): ?AuthorizationCode
    {
        return null;
    }

    public function purgeExpired(): int
    {
        return 0;
    }
}
