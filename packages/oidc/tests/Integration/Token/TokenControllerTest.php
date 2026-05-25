<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Oidc\ClientRegistry\OidcClientLookup;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Oidc\Keys\SigningKey;
use Waaseyaa\Oidc\Repository\AuthorizationCode;
use Waaseyaa\Oidc\Repository\AuthorizationCodeRepositoryInterface;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\IdTokenMinter;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;
use Waaseyaa\Oidc\Token\PkceVerifier;
use Waaseyaa\Oidc\Token\RefreshTokenGrantHandler;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;
use Waaseyaa\Oidc\Token\TokenController;
use Waaseyaa\Oidc\Token\TokenRequestValidator;

#[CoversClass(TokenController::class)]
final class TokenControllerTest extends TestCase
{
    private const ISSUER = 'https://idp.example';
    private const REDIRECT_URI = 'https://app.example/callback';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private string $privateKeyPem;
    private string $publicKeyPem;
    private SqlEntityStorage $storage;

    protected function setUp(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($resource);

        $private = '';
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        $this->privateKeyPem = $private;
        $this->publicKeyPem = $details['key'];

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

        $this->storage = new SqlEntityStorage($entityType, $database, new EventDispatcher());
    }

    #[Test]
    public function happyPathPublicClientReturnsTokenJson(): void
    {
        $controller = $this->controller(
            clients: [$this->publicClient('spa-1')],
            codes: [$this->seedCode('code-ok', 'spa-1', nonce: 'nonce-abc')],
        );

        $response = ($controller)($this->postForm([
            'grant_type' => 'authorization_code',
            'code' => 'code-ok',
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
            'client_id' => 'spa-1',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('Bearer', $payload['token_type']);
        self::assertSame(3600, $payload['expires_in']);
        self::assertIsString($payload['access_token']);
        self::assertNotEmpty($payload['access_token']);
        self::assertIsString($payload['id_token']);

        $claims = $this->decodeIdTokenClaims($payload['id_token']);
        self::assertSame(self::ISSUER, $claims['iss']);
        self::assertSame('spa-1', $claims['aud']);
        self::assertSame('nonce-abc', $claims['nonce']);
    }

    #[Test]
    public function idTokenOmitsNonceClaimWhenCodeHasNoNonce(): void
    {
        $controller = $this->controller(
            clients: [$this->publicClient('spa-1')],
            codes: [$this->seedCode('code-no-nonce', 'spa-1', nonce: null)],
        );

        $response = ($controller)($this->postForm($this->validForm('code-no-nonce', 'spa-1')));
        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        $claims = $this->decodeIdTokenClaims($payload['id_token']);
        self::assertArrayNotHasKey('nonce', $claims);
    }

    #[Test]
    public function rejectsNonPostWith405(): void
    {
        $controller = $this->controller();

        $response = ($controller)(Request::create('/token', 'GET'));

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function rejectsMissingGrantTypeWith400InvalidRequest(): void
    {
        $controller = $this->controller();

        $form = $this->validForm('code-ok', 'spa-1');
        unset($form['grant_type']);

        $response = ($controller)($this->postForm($form));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_request', $this->jsonError($response));
    }

    #[Test]
    public function unknownClientIdReturns401InvalidClient(): void
    {
        $controller = $this->controller(clients: []);

        $response = ($controller)($this->postForm($this->validForm('code-ok', 'ghost')));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_client', $this->jsonError($response));
    }

    #[Test]
    public function missingClientIdAndNoBasicAuthReturns401InvalidClient(): void
    {
        $controller = $this->controller();

        $form = $this->validForm('code-ok', 'spa-1');
        unset($form['client_id']);

        $response = ($controller)($this->postForm($form));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_client', $this->jsonError($response));
    }

    #[Test]
    public function unknownOrExpiredCodeReturns400InvalidGrant(): void
    {
        $controller = $this->controller(
            clients: [$this->publicClient('spa-1')],
            codes: [],
        );

        $response = ($controller)($this->postForm($this->validForm('missing-code', 'spa-1')));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_grant', $this->jsonError($response));
    }

    #[Test]
    public function codeIssuedToDifferentClientReturns400InvalidGrant(): void
    {
        $controller = $this->controller(
            clients: [$this->publicClient('spa-1'), $this->publicClient('spa-2')],
            codes: [$this->seedCode('code-for-spa1', 'spa-1')],
        );

        $response = ($controller)($this->postForm($this->validForm('code-for-spa1', 'spa-2')));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_grant', $this->jsonError($response));
    }

    #[Test]
    public function redirectUriMismatchReturns400InvalidGrant(): void
    {
        $controller = $this->controller(
            clients: [$this->publicClient('spa-1')],
            codes: [$this->seedCode('code-ok', 'spa-1')],
        );

        $form = $this->validForm('code-ok', 'spa-1');
        $form['redirect_uri'] = 'https://app.example/OTHER';

        $response = ($controller)($this->postForm($form));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_grant', $this->jsonError($response));
    }

    #[Test]
    public function pkceVerifierMismatchReturns400InvalidGrant(): void
    {
        $controller = $this->controller(
            clients: [$this->publicClient('spa-1')],
            codes: [$this->seedCode('code-ok', 'spa-1')],
        );

        $form = $this->validForm('code-ok', 'spa-1');
        $form['code_verifier'] = str_repeat('x', 43);

        $response = ($controller)($this->postForm($form));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_grant', $this->jsonError($response));
    }

    #[Test]
    public function confidentialClientWithoutAuthReturns401InvalidClient(): void
    {
        $controller = $this->controller(
            clients: [$this->confidentialClient('conf-1', 'shhh')],
            codes: [$this->seedCode('code-ok', 'conf-1')],
        );

        $response = ($controller)($this->postForm($this->validForm('code-ok', 'conf-1')));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_client', $this->jsonError($response));
    }

    #[Test]
    public function confidentialClientWithWrongSecretReturns401InvalidClient(): void
    {
        $controller = $this->controller(
            clients: [$this->confidentialClient('conf-1', 'correct-secret')],
            codes: [$this->seedCode('code-ok', 'conf-1')],
        );

        $request = $this->postForm($this->validForm('code-ok', 'conf-1'));
        $request->headers->set('Authorization', 'Basic ' . base64_encode('conf-1:WRONG'));

        $response = ($controller)($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_client', $this->jsonError($response));
    }

    #[Test]
    public function confidentialClientWithBasicAuthSucceeds(): void
    {
        $controller = $this->controller(
            clients: [$this->confidentialClient('conf-1', 'correct-secret')],
            codes: [$this->seedCode('code-ok', 'conf-1')],
        );

        $form = $this->validForm('code-ok', 'conf-1');
        unset($form['client_id']);
        $request = $this->postForm($form);
        $request->headers->set('Authorization', 'Basic ' . base64_encode('conf-1:correct-secret'));

        $response = ($controller)($request);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function confidentialClientWithClientSecretPostSucceeds(): void
    {
        $controller = $this->controller(
            clients: [$this->confidentialClient('conf-1', 'correct-secret')],
            codes: [$this->seedCode('code-ok', 'conf-1')],
        );

        $form = $this->validForm('code-ok', 'conf-1');
        $form['client_secret'] = 'correct-secret';

        $response = ($controller)($this->postForm($form));
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $form
     */
    private function postForm(array $form): Request
    {
        $request = Request::create('/token', 'POST', $form);
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private function validForm(string $code, string $clientId): array
    {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
            'client_id' => $clientId,
        ];
    }

    /**
     * @param list<OidcClient> $clients
     * @param list<AuthorizationCode> $codes
     */
    private function controller(array $clients = [], array $codes = []): TokenController
    {
        foreach ($clients as $client) {
            $this->storage->save($client);
        }

        $db = DBALDatabase::createSqlite();
        $accessTokenIssuer = new AccessTokenIssuer($db);
        $refreshTokenIssuer = new RefreshTokenIssuer($db);

        return new TokenController(
            clientLookup: new OidcClientLookup($this->storage),
            validator: new TokenRequestValidator(),
            pkceVerifier: new PkceVerifier(),
            codeRepository: $this->fakeCodeRepository($codes),
            idTokenMinter: new IdTokenMinter($this->keyProvider()),
            accessTokenIssuer: $accessTokenIssuer,
            refreshTokenIssuer: $refreshTokenIssuer,
            refreshGrantHandler: new RefreshTokenGrantHandler($refreshTokenIssuer, $accessTokenIssuer, new IdTokenMinter($this->keyProvider())),
            issuer: self::ISSUER,
            clock: fn (): DateTimeImmutable => new DateTimeImmutable('2026-04-18T12:00:00Z'),
        );
    }

    private function publicClient(string $clientId): OidcClient
    {
        /** @var OidcClient $client */
        $client = $this->storage->create([
            'client_id' => $clientId,
            'name' => $clientId,
            'redirect_uris' => [self::REDIRECT_URI],
            'scopes' => ['openid'],
            'grant_types' => ['authorization_code'],
            'is_confidential' => false,
        ]);

        return $client;
    }

    private function confidentialClient(string $clientId, string $plainSecret): OidcClient
    {
        $hash = password_hash($plainSecret, PASSWORD_BCRYPT);
        self::assertIsString($hash);

        /** @var OidcClient $client */
        $client = $this->storage->create([
            'client_id' => $clientId,
            'name' => $clientId,
            'redirect_uris' => [self::REDIRECT_URI],
            'scopes' => ['openid'],
            'grant_types' => ['authorization_code'],
            'is_confidential' => true,
            'client_secret_hash' => $hash,
        ]);

        return $client;
    }

    private function seedCode(string $code, string $clientId, ?string $nonce = null): AuthorizationCode
    {
        $now = (new DateTimeImmutable('2026-04-18T12:00:00Z'))->getTimestamp();

        return new AuthorizationCode(
            code: $code,
            clientId: $clientId,
            accountId: '42',
            redirectUri: self::REDIRECT_URI,
            scopes: ['openid'],
            codeChallenge: self::CHALLENGE,
            codeChallengeMethod: 'S256',
            issuedAt: $now,
            expiresAt: $now + 60,
            consumedAt: null,
            nonce: $nonce,
        );
    }

    /**
     * @param list<AuthorizationCode> $codes
     */
    private function fakeCodeRepository(array $codes): AuthorizationCodeRepositoryInterface
    {
        return new class ($codes) implements AuthorizationCodeRepositoryInterface {
            /** @var array<string, AuthorizationCode> */
            private array $by_code;

            /** @param list<AuthorizationCode> $codes */
            public function __construct(array $codes)
            {
                $this->by_code = [];
                foreach ($codes as $code) {
                    $this->by_code[$code->code] = $code;
                }
            }

            public function issue(
                string $clientId,
                AccountInterface $account,
                string $redirectUri,
                array $scopes,
                string $codeChallenge,
                string $codeChallengeMethod,
                ?string $nonce = null,
            ): AuthorizationCode {
                throw new \RuntimeException('issue() not used in these tests');
            }

            public function consume(string $code): ?AuthorizationCode
            {
                $stored = $this->by_code[$code] ?? null;
                if ($stored === null) {
                    return null;
                }
                unset($this->by_code[$code]);

                return $stored;
            }

            public function purgeExpired(): int
            {
                return 0;
            }
        };
    }

    private function keyProvider(): KeyMaterialProviderInterface
    {
        return new class ($this->privateKeyPem, $this->publicKeyPem) implements KeyMaterialProviderInterface {
            public function __construct(private string $private_pem, private string $public_pem)
            {
            }

            public function currentKey(): SigningKey
            {
                return new SigningKey('test-kid', 'RS256', $this->public_pem, $this->private_pem);
            }

            public function allActive(): array
            {
                return [$this->currentKey()];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeIdTokenClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $padded = str_pad($parts[1], (int) (ceil(strlen($parts[1]) / 4) * 4), '=', STR_PAD_RIGHT);
        $claims = json_decode(base64_decode(strtr($padded, '-_', '+/'), true) ?: '', true);
        self::assertIsArray($claims);

        return $claims;
    }

    private function jsonError(\Symfony\Component\HttpFoundation\Response $response): string
    {
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);

        return (string) ($payload['error'] ?? '');
    }
}
