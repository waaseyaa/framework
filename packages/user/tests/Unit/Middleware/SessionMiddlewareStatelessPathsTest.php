<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\Middleware\CsrfMiddleware;
use Waaseyaa\User\Middleware\SessionMiddleware;

/**
 * Issue #2146: anonymous GET/HEAD requests to configured stateless path
 * prefixes must not start a PHP session (and therefore must not mint
 * PHPSESSID or XSRF-TOKEN cookies). Requests that already carry the
 * session cookie resume normally, non-GET methods keep their sessions,
 * and an empty prefix list preserves the old behavior byte for byte.
 */
#[CoversClass(SessionMiddleware::class)]
final class SessionMiddlewareStatelessPathsTest extends TestCase
{
    private function middleware(array $statelessPaths): SessionMiddleware
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);

        return new SessionMiddleware(
            $repository,
            statelessPathPrefixes: $statelessPaths,
        );
    }

    /**
     * @return array{0: HttpHandlerInterface, 1: callable(): ?AccountInterface}
     */
    private function capturingNext(): array
    {
        $captured = new class {
            public ?AccountInterface $account = null;
        };
        $next = new class($captured) implements HttpHandlerInterface {
            public function __construct(private object $captured) {}

            public function handle(Request $request): Response
            {
                $this->captured->account = $request->attributes->get('_account');

                return new Response('ok');
            }
        };

        return [$next, static fn (): ?AccountInterface => $captured->account];
    }

    #[Test]
    #[RunInSeparateProcess]
    public function anonymous_get_on_a_stateless_path_starts_no_session(): void
    {
        [$next, $account] = $this->capturingNext();

        $this->middleware(['/docs', '/llms.txt'])
            ->process(Request::create('/docs/specs/entity-system'), $next);

        $this->assertSame(\PHP_SESSION_NONE, session_status(), 'no session may be started');
        $this->assertInstanceOf(AnonymousUser::class, $account());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function exact_prefix_match_is_stateless_too(): void
    {
        [$next] = $this->capturingNext();

        $this->middleware(['/llms.txt'])->process(Request::create('/llms.txt'), $next);

        $this->assertSame(\PHP_SESSION_NONE, session_status());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_request_carrying_the_session_cookie_resumes_normally(): void
    {
        [$next] = $this->capturingNext();

        $request = Request::create('/docs');
        $request->cookies->set(session_name() ?: 'PHPSESSID', 'existing-session-id');

        $this->middleware(['/docs'])->process($request, $next);

        $this->assertSame(\PHP_SESSION_ACTIVE, session_status(), 'existing sessions must resume on stateless paths');
    }

    #[Test]
    #[RunInSeparateProcess]
    public function post_requests_keep_their_sessions_even_on_stateless_paths(): void
    {
        [$next] = $this->capturingNext();

        $this->middleware(['/docs'])->process(Request::create('/docs/form', 'POST'), $next);

        $this->assertSame(\PHP_SESSION_ACTIVE, session_status(), 'non-GET methods are never stateless');
    }

    #[Test]
    #[RunInSeparateProcess]
    public function paths_outside_the_prefixes_behave_exactly_as_before(): void
    {
        [$next] = $this->capturingNext();

        $this->middleware(['/docs'])->process(Request::create('/admin'), $next);

        $this->assertSame(\PHP_SESSION_ACTIVE, session_status());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function prefix_matching_does_not_leak_to_sibling_paths(): void
    {
        [$next] = $this->capturingNext();

        $this->middleware(['/docs'])->process(Request::create('/docsearch'), $next);

        $this->assertSame(\PHP_SESSION_ACTIVE, session_status(), '/docs must not match /docsearch');
    }

    #[Test]
    #[RunInSeparateProcess]
    public function empty_prefix_list_preserves_the_old_behavior(): void
    {
        [$next] = $this->capturingNext();

        $this->middleware([])->process(Request::create('/docs'), $next);

        $this->assertSame(\PHP_SESSION_ACTIVE, session_status());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function stateless_responses_get_no_xsrf_cookie_either(): void
    {
        [$next] = $this->capturingNext();
        $session = $this->middleware(['/docs']);
        $csrf = new CsrfMiddleware();

        // Pipeline shape: CSRF wraps the session middleware's handling,
        // mirroring the kernel's middleware chain closely enough to
        // observe cookie attachment on the response.
        $inner = new class($session, $next) implements HttpHandlerInterface {
            public function __construct(
                private SessionMiddleware $session,
                private HttpHandlerInterface $next,
            ) {}

            public function handle(Request $request): Response
            {
                return $this->session->process($request, $this->next);
            }
        };

        $request = Request::create('/docs/specs/entity-system');
        $response = $csrf->process($request, $inner);
        $response->headers->set('Content-Type', 'text/html');

        $names = array_map(
            static fn ($cookie) => $cookie->getName(),
            $response->headers->getCookies(),
        );

        $this->assertSame(\PHP_SESSION_NONE, session_status());
        $this->assertNotContains('XSRF-TOKEN', $names, 'no session token means no XSRF cookie');
    }

    // ------------------------------------------------------------------
    // #2154: a "/" entry means the root path, not every path
    // ------------------------------------------------------------------

    #[Test]
    #[RunInSeparateProcess]
    public function a_root_entry_makes_the_homepage_stateless(): void
    {
        [$next] = $this->capturingNext();

        $this->middleware(['/'])->process(Request::create('/'), $next);

        $this->assertSame(\PHP_SESSION_NONE, session_status());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_root_entry_does_not_make_the_admin_login_stateless(): void
    {
        // The failure this closes is silent and severe: /admin/login is a GET
        // that must mint a CSRF token, and with no session CsrfMiddleware
        // withholds the XSRF cookie by design (#2146) — so an app listing "/"
        // for its homepage would serve a login form that cannot work.
        [$next] = $this->capturingNext();

        $this->middleware(['/'])->process(Request::create('/admin/login'), $next);

        $this->assertSame(\PHP_SESSION_ACTIVE, session_status());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_root_entry_does_not_make_arbitrary_paths_stateless(): void
    {
        [$next] = $this->capturingNext();

        $this->middleware(['/'])->process(Request::create('/news/some-article'), $next);

        $this->assertSame(\PHP_SESSION_ACTIVE, session_status());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_root_entry_composes_with_named_prefixes(): void
    {
        // The realistic configuration: homepage + a few public sections.
        [$next] = $this->capturingNext();

        $this->middleware(['/', '/news'])->process(Request::create('/news/some-article'), $next);

        $this->assertSame(\PHP_SESSION_NONE, session_status());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_root_entry_still_yields_a_session_for_non_get_methods(): void
    {
        [$next] = $this->capturingNext();

        $this->middleware(['/'])->process(Request::create('/', 'POST'), $next);

        $this->assertSame(\PHP_SESSION_ACTIVE, session_status());
    }
}
