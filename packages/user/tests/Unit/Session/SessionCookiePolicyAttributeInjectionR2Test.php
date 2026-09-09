<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\User\Middleware\CsrfMiddleware;
use Waaseyaa\User\Session\InvalidSessionCookiePolicyException;
use Waaseyaa\User\Session\SessionCookiePolicy;

/**
 * Review-repair discriminators for #3047 r2 (semicolon path/domain injection).
 */
#[CoversClass(SessionCookiePolicy::class)]
#[CoversClass(CsrfMiddleware::class)]
final class SessionCookiePolicyAttributeInjectionR2Test extends TestCase
{
    #[Test]
    public function semicolon_path_is_rejected(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['path' => '/admin; HttpOnly']);
    }

    #[Test]
    public function semicolon_domain_is_rejected(): void
    {
        $this->expectException(InvalidSessionCookiePolicyException::class);
        new SessionCookiePolicy(['domain' => 'example.test; Secure']);
    }

    #[Test]
    public function valid_path_and_domain_still_construct(): void
    {
        $policy = new SessionCookiePolicy([
            'path' => '/admin',
            'domain' => 'example.test',
        ]);
        $this->assertSame('/admin', $policy->path());
        $this->assertSame('example.test', $policy->domain());
    }

    #[Test]
    public function csrf_set_cookie_header_does_not_inject_from_path(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['_csrf_token'] = 'token-value-for-header-proof';

        try {
            // Constructing with semicolon must fail before serialization.
            $this->expectException(InvalidSessionCookiePolicyException::class);
            $policy = new SessionCookiePolicy(['path' => '/admin; HttpOnly']);
            $request = Request::create('/');
            $response = new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            CsrfMiddleware::attachCookieIfHtml($request, $response, $policy);
        } finally {
            unset($_SESSION['_csrf_token']);
            if (session_status() === \PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }

    #[Test]
    public function csrf_set_cookie_serializes_clean_path_without_extra_attributes(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_start();
        $_SESSION['_csrf_token'] = 'abc123token';

        try {
            $policy = new SessionCookiePolicy(['path' => '/admin', 'samesite' => 'Lax']);
            $request = Request::create('https://example.test/');
            $request->server->set('HTTPS', 'on');
            $response = new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            CsrfMiddleware::attachCookieIfHtml($request, $response, $policy);

            $cookies = $response->headers->getCookies();
            $this->assertNotEmpty($cookies);
            $header = (string) $cookies[0];
            $this->assertStringContainsString('path=/admin', strtolower($header));
            $this->assertDoesNotMatchRegularExpression('/path=\/admin;\s*httponly/i', $header);
            // Exactly one path= attribute.
            $this->assertSame(1, preg_match_all('/(?:^|;\s*)path=/i', $header));
        } finally {
            unset($_SESSION['_csrf_token']);
            session_write_close();
        }
    }

    #[Test]
    public function csrf_set_cookie_serializes_clean_domain_without_secure_injection(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_start();
        $_SESSION['_csrf_token'] = 'abc123token';

        try {
            $policy = new SessionCookiePolicy([
                'domain' => 'example.test',
                'secure' => false,
                'samesite' => 'Lax',
            ]);
            $request = Request::create('http://example.test/');
            $response = new Response('ok', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            CsrfMiddleware::attachCookieIfHtml($request, $response, $policy);

            $cookies = $response->headers->getCookies();
            $this->assertNotEmpty($cookies);
            $header = (string) $cookies[0];
            $this->assertStringContainsString('domain=example.test', strtolower($header));
            $this->assertStringNotContainsString('domain=example.test; secure', strtolower($header));
            // secure attribute must be absent when configured false on plaintext.
            $this->assertDoesNotMatchRegularExpression('/(?:^|;\s*)secure(?:;|$)/i', $header);
        } finally {
            unset($_SESSION['_csrf_token']);
            session_write_close();
        }
    }

    #[Test]
    public function cookie_create_with_injected_path_would_serialize_extra_attribute(): void
    {
        // Document the Symfony serialization hazard that the policy must reject.
        $cookie = Cookie::create('XSRF-TOKEN')
            ->withValue('token')
            ->withPath('/admin; HttpOnly')
            ->withHttpOnly(false)
            ->withSameSite('lax');
        $header = (string) $cookie;
        $this->assertMatchesRegularExpression('/path=\/admin;\s*httponly/i', $header);
    }
}
