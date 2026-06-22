<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Oidc\Authorize\AuthorizeController;
use Waaseyaa\Oidc\Consent\ConsentRepository;
use Waaseyaa\Oidc\Consent\ConsentScreenController;
use Waaseyaa\Oidc\Repository\AuthorizationCode;
use Waaseyaa\Oidc\Repository\AuthorizationCodeRepositoryInterface;
use Waaseyaa\Oidc\Userinfo\UserinfoClaimResolver;
use Waaseyaa\User\Middleware\CsrfMiddleware;

/**
 * B-2: the OIDC consent decision must be CSRF-protected. The consent form must carry the
 * `_csrf_token` the global CsrfMiddleware requires (its absence both breaks legitimate
 * approvals AND was the audit's reported gap), and an approve POST without a valid token
 * must NOT issue an authorization code.
 */
#[CoversClass(ConsentScreenController::class)]
final class ConsentScreenControllerTest extends TestCase
{
    private const PENDING = [
        'client_id' => 'demo-client',
        'redirect_uri' => 'https://app.example/callback',
        'scopes' => ['openid', 'profile'],
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ];

    protected function setUp(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    public function testConsentFormIncludesCsrfTokenField(): void
    {
        $codeRepo = $this->createMock(AuthorizationCodeRepositoryInterface::class);
        $codeRepo->expects($this->never())->method('issue');
        $controller = $this->controller($codeRepo);

        $response = $controller($this->request('GET'));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('name="_csrf_token"', $body);
        // The embedded token must be the one the middleware will validate against.
        $this->assertStringContainsString(CsrfMiddleware::token(), $body);
    }

    public function testApproveWithoutCsrfTokenIssuesNoCode(): void
    {
        $codeRepo = $this->createMock(AuthorizationCodeRepositoryInterface::class);
        $codeRepo->expects($this->never())->method('issue');
        $controller = $this->controller($codeRepo);

        $response = $controller($this->request('POST', ['action' => 'approve']));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testApproveWithInvalidCsrfTokenIssuesNoCode(): void
    {
        $codeRepo = $this->createMock(AuthorizationCodeRepositoryInterface::class);
        $codeRepo->expects($this->never())->method('issue');
        $controller = $this->controller($codeRepo);

        $response = $controller($this->request('POST', ['action' => 'approve', '_csrf_token' => 'not-the-token']));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testApproveWithValidCsrfTokenIssuesCode(): void
    {
        $token = CsrfMiddleware::token();
        $codeRepo = $this->createMock(AuthorizationCodeRepositoryInterface::class);
        $codeRepo->expects($this->once())->method('issue')->willReturn(
            new AuthorizationCode(
                code: 'auth-code-123',
                clientId: 'demo-client',
                accountId: '5',
                redirectUri: 'https://app.example/callback',
                scopes: ['openid', 'profile'],
                codeChallenge: 'abc',
                codeChallengeMethod: 'S256',
                issuedAt: 0,
                expiresAt: 0,
            ),
        );
        $controller = $this->controller($codeRepo);

        $response = $controller($this->request('POST', ['action' => 'approve', '_csrf_token' => $token]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('code=auth-code-123', (string) $response->headers->get('Location'));
    }

    private function controller(AuthorizationCodeRepositoryInterface $codeRepo): ConsentScreenController
    {
        return new ConsentScreenController(
            new ConsentRepository(DBALDatabase::createSqlite()),
            new UserinfoClaimResolver(),
            $codeRepo,
        );
    }

    /**
     * @param array<string, string> $post
     */
    private function request(string $method, array $post = []): Request
    {
        $request = Request::create('/oidc/consent', $method, $post);

        $session = new Session(new MockArraySessionStorage());
        $session->set(AuthorizeController::SESSION_KEY, self::PENDING);
        $request->setSession($session);

        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(true);
        $account->method('id')->willReturn(5);
        $request->attributes->set('_account', $account);

        return $request;
    }
}
