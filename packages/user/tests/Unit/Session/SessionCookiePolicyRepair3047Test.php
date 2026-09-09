<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\Session\InvalidSessionCookiePolicyException;
use Waaseyaa\User\Session\SessionCookiePolicy;

/**
 * Review-repair discriminators for #3047 blockers (Codex review of c4fc2da).
 */
#[CoversClass(SessionCookiePolicy::class)]
#[CoversClass(SessionMiddleware::class)]
final class SessionCookiePolicyRepair3047Test extends TestCase
{
    private function passthrough(): HttpHandlerInterface
    {
        return new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('ok');
            }
        };
    }

    #[Test]
    #[RunInSeparateProcess]
    public function host_bound_cookie_on_stateless_path_resumes_configured_session(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $middleware = new SessionMiddleware(
            $repository,
            sessionCookieOptions: ['host_bound' => true],
            statelessPathPrefixes: ['/docs'],
        );

        $request = Request::create('/docs');
        $request->cookies->set(SessionCookiePolicy::HOST_BOUND_SESSION_COOKIE_NAME, 'existing-host-session');

        $middleware->process($request, $this->passthrough());

        $this->assertSame(
            \PHP_SESSION_ACTIVE,
            session_status(),
            'Configured __Host- session cookie must resume identity on a otherwise-stateless path.',
        );
        $this->assertSame(SessionCookiePolicy::HOST_BOUND_SESSION_COOKIE_NAME, session_name());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function configured_secure_true_rejects_active_insecure_session(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name('PHPSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        try {
            $policy = new SessionCookiePolicy(['secure' => true]);
            try {
                $policy->assertCompatibleWithActiveSession();
                $this->fail('Expected rejection when configured Secure=true but active session is insecure.');
            } catch (InvalidSessionCookiePolicyException) {
                $this->assertTrue(true);
            }
        } finally {
            session_write_close();
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    public function host_bound_rejects_active_session_without_httponly(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name(SessionCookiePolicy::HOST_BOUND_SESSION_COOKIE_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        session_start();

        try {
            $policy = new SessionCookiePolicy(['host_bound' => true]);
            try {
                $policy->assertCompatibleWithActiveSession();
                $this->fail('Expected rejection when host-bound active session lacks HttpOnly.');
            } catch (InvalidSessionCookiePolicyException) {
                $this->assertTrue(true);
            }
        } finally {
            session_write_close();
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    public function configured_samesite_rejects_active_session_mismatch(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name('PHPSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'None',
        ]);
        session_start();

        try {
            $policy = new SessionCookiePolicy(['samesite' => 'Lax']);
            try {
                $policy->assertCompatibleWithActiveSession();
                $this->fail('Expected rejection when active SameSite disagrees with configured Lax.');
            } catch (InvalidSessionCookiePolicyException) {
                $this->assertTrue(true);
            }
        } finally {
            session_write_close();
        }
    }

    #[Test]
    public function malformed_host_bound_string_is_rejected(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['host_bound' => 'treu']);
    }

    #[Test]
    public function array_path_is_rejected(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['path' => []]);
    }

    #[Test]
    public function array_domain_is_rejected(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['domain' => []]);
    }

    #[Test]
    public function crlf_path_is_rejected(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['path' => "/admin\r\n"]);
    }

    #[Test]
    public function crlf_domain_is_rejected(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['domain' => "example.test\r\n"]);
    }

    #[Test]
    public function legacy_compatible_defaults_still_construct(): void
    {
        $policy = new SessionCookiePolicy();
        $this->assertFalse($policy->hostBound());
        $this->assertSame(SessionCookiePolicy::DEFAULT_CSRF_COOKIE_NAME, $policy->csrfName());
        $this->assertSame('/', $policy->path());
        $this->assertNull($policy->domain());
    }
}
