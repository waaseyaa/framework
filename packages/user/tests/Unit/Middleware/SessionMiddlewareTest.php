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
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserSessionSnapshot;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\Middleware\ResponseCacheControlMiddleware;
use Waaseyaa\User\Session\NativeSession;
use Waaseyaa\User\User;

#[CoversClass(SessionMiddleware::class)]
final class SessionMiddlewareTest extends TestCase
{
    private function internalFields(int $generation = 0): UserInternalFieldReaderInterface
    {
        $reader = $this->createStub(UserInternalFieldReaderInterface::class);
        $reader->method('sessionIdentity')->willReturn(new UserSessionSnapshot('', '', [], $generation));
        return $reader;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    #[Test]
    public function sets_anonymous_user_when_no_session(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->never())->method('find');

        $middleware = new SessionMiddleware($repository, internalFields: $this->internalFields());
        $request = Request::create('/test');

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(AnonymousUser::class, $capturedAccount);
    }

    #[Test]
    public function resolves_user_from_session(): void
    {
        $user = new User(['uid' => 42, 'name' => 'admin', 'permissions' => ['access content']]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('42')
            ->willReturn($user);

        $middleware = new SessionMiddleware($repository, internalFields: $this->internalFields());
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42, 'waaseyaa_session_generation' => 0]);

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(User::class, $capturedAccount);
        $this->assertSame(42, $capturedAccount->id());
    }

    #[Test]
    public function rejects_a_session_without_a_generation(): void
    {
        $user = new User(['uid' => 42, 'name' => 'admin']);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($user);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

        $capturedAccount = null;
        new SessionMiddleware($repository, internalFields: $this->internalFields(2))->process($request, new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}
            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        });

        self::assertInstanceOf(AnonymousUser::class, $capturedAccount);
        self::assertSame([], $request->attributes->get('_session'));
    }

    #[Test]
    public function rejects_and_clears_a_session_from_an_older_generation(): void
    {
        $user = new User(['uid' => 42, 'name' => 'admin', 'session_generation' => 2]);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($user);
        $request = Request::create('/test');
        $request->attributes->set('_session', [
            'waaseyaa_uid' => 42,
            'waaseyaa_session_generation' => 1,
            'unrelated' => 'preserved',
        ]);

        $capturedAccount = null;
        new SessionMiddleware($repository, internalFields: $this->internalFields(2))->process($request, new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}
            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        });

        self::assertInstanceOf(AnonymousUser::class, $capturedAccount);
        self::assertSame(['unrelated' => 'preserved'], $request->attributes->get('_session'));
    }

    #[Test]
    public function falls_back_to_anonymous_when_user_not_found(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('999')
            ->willReturn(null);

        $middleware = new SessionMiddleware($repository);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 999, 'waaseyaa_session_generation' => 0]);

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(AnonymousUser::class, $capturedAccount);
    }

    #[Test]
    public function falls_back_to_anonymous_when_storage_throws(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('42')
            ->willThrowException(new \RuntimeException('Database unavailable'));

        $middleware = new SessionMiddleware($repository);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42, 'waaseyaa_session_generation' => 0]);

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(AnonymousUser::class, $capturedAccount);
    }

    #[Test]
    public function uses_dev_fallback_when_no_session_and_fallback_provided(): void
    {
        $devAccount = new DevAdminAccount();
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->never())->method('find');

        $middleware = new SessionMiddleware($repository, $devAccount, internalFields: $this->internalFields());
        $request = Request::create('/test');

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(DevAdminAccount::class, $capturedAccount);
        $this->assertSame(PHP_INT_MAX, $capturedAccount->id());
    }

    #[Test]
    public function ignores_dev_fallback_when_session_exists(): void
    {
        $devAccount = new DevAdminAccount();
        $user = new User(['uid' => 42, 'name' => 'admin', 'permissions' => ['access content']]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('42')
            ->willReturn($user);

        $middleware = new SessionMiddleware($repository, $devAccount, internalFields: $this->internalFields());
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42, 'waaseyaa_session_generation' => 0]);

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(User::class, $capturedAccount);
        $this->assertSame(42, $capturedAccount->id());
    }

    #[Test]
    public function does_not_use_dev_fallback_when_session_uid_exists_but_user_not_found(): void
    {
        $devAccount = new DevAdminAccount();
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('999')
            ->willReturn(null);

        $middleware = new SessionMiddleware($repository, $devAccount);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 999, 'waaseyaa_session_generation' => 0]);

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(AnonymousUser::class, $capturedAccount);
    }

    #[Test]
    public function does_not_use_dev_fallback_when_session_uid_exists_but_storage_throws(): void
    {
        $devAccount = new DevAdminAccount();
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('42')
            ->willThrowException(new \RuntimeException('Database unavailable'));

        $middleware = new SessionMiddleware($repository, $devAccount);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42, 'waaseyaa_session_generation' => 0]);

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(AnonymousUser::class, $capturedAccount);
    }

    #[Test]
    public function passes_response_from_next_handler(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $middleware = new SessionMiddleware($repository);
        $request = Request::create('/test');

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('downstream', 201);
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('downstream', $response->getContent());
    }

    #[Test]
    public function does_not_override_existing_account_attribute(): void
    {
        $existing = new User(['uid' => 88, 'name' => 'token-user']);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->never())->method('find');

        $middleware = new SessionMiddleware($repository);
        $request = Request::create('/test');
        $request->attributes->set('_account', $existing);

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertSame($existing, $capturedAccount);
    }

    #[Test]
    public function attaches_native_session_to_request(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $middleware = new SessionMiddleware($repository);
        $request = Request::create('/test');

        $capturedSession = null;
        $next = new class($capturedSession) implements HttpHandlerInterface {
            public function __construct(private mixed &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->hasSession() ? $request->getSession() : null;
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(NativeSession::class, $capturedSession);
    }

    #[Test]
    public function marks_stateful_requests_for_final_private_cache_reconciliation(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $request = Request::create('/account');
        $request->attributes->set('_session', []);

        new SessionMiddleware($repository)->process($request, new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('ok');
            }
        });

        $this->assertTrue($request->attributes->get(ResponseCacheControlMiddleware::SESSION_BOUND_ATTRIBUTE));
    }

    #[Test]
    public function leaves_cookie_free_stateless_requests_public_cache_eligible(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $request = Request::create('/news');

        new SessionMiddleware($repository, statelessPathPrefixes: ['/news'])->process(
            $request,
            new class implements HttpHandlerInterface {
                public function handle(Request $request): Response
                {
                    return new Response('ok');
                }
            },
        );

        $this->assertFalse($request->attributes->has(ResponseCacheControlMiddleware::SESSION_BOUND_ATTRIBUTE));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function disables_php_cache_limiter_before_starting_a_session(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        session_cache_limiter('nocache');
        ini_set('session.use_cookies', '0');

        new SessionMiddleware($repository)->process(
            Request::create('/account'),
            new class implements HttpHandlerInterface {
                public function handle(Request $request): Response
                {
                    return new Response('ok');
                }
            },
        );

        $this->assertSame('', session_cache_limiter(), 'PHP must not emit a second Cache-Control authority.');
        session_write_close();
    }

    #[Test]
    public function does_not_replace_existing_session(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $middleware = new SessionMiddleware($repository);
        $request = Request::create('/test');

        $existingSession = new NativeSession();
        $request->setSession($existingSession);

        $capturedSession = null;
        $next = new class($capturedSession) implements HttpHandlerInterface {
            public function __construct(private mixed &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->getSession();
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertSame($existingSession, $capturedSession);
    }

    #[Test]
    public function mirrors_resolved_account_into_account_context(): void
    {
        $user = new User(['uid' => 42, 'name' => 'admin', 'permissions' => ['access content']]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('42')
            ->willReturn($user);

        $context = new RequestAccountContext();
        $middleware = new SessionMiddleware($repository, accountContext: $context, internalFields: $this->internalFields());
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42, 'waaseyaa_session_generation' => 0]);

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        // Both surfaces agree: the `_account` attribute and the context hold
        // the same object.
        $this->assertSame($user, $capturedAccount);
        $this->assertSame($user, $context->current());
    }

    #[Test]
    public function mirrors_anonymous_fallback_into_account_context(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->never())->method('find');

        $context = new RequestAccountContext();
        $middleware = new SessionMiddleware($repository, accountContext: $context);
        $request = Request::create('/test');

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        // Anonymous (id 0) is an actor — the context holds it, not null.
        $this->assertInstanceOf(AnonymousUser::class, $context->current());
        $this->assertSame(0, $context->current()->id());
    }

    #[Test]
    public function mirrors_preset_authenticated_account_into_account_context(): void
    {
        $existing = new User(['uid' => 88, 'name' => 'token-user']);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->never())->method('find');

        $context = new RequestAccountContext();
        $middleware = new SessionMiddleware($repository, accountContext: $context);
        $request = Request::create('/test');
        $request->attributes->set('_account', $existing);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        // The early-return branch (BearerAuthMiddleware already resolved an
        // account) must mirror the pre-set account too.
        $this->assertSame($existing, $context->current());
    }

    #[Test]
    public function works_without_account_context_param(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->never())->method('find');

        // Legacy construction — no context param. The `?->` guard means no
        // error, and `_account` behavior is unchanged.
        $middleware = new SessionMiddleware($repository);
        $request = Request::create('/test');

        $capturedAccount = null;
        $next = new class($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$ref) {}

            public function handle(Request $request): Response
            {
                $this->ref = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame('ok', $response->getContent());
        $this->assertInstanceOf(AnonymousUser::class, $capturedAccount);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function applies_session_cookie_ini_when_configured(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $keys = [
            'session.cookie_httponly',
            'session.cookie_secure',
            'session.cookie_samesite',
            'session.use_strict_mode',
        ];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = ini_get($key);
        }

        try {
            $_SERVER['HTTPS'] = 'on';
            $middleware = new SessionMiddleware($repository, null, null, [
                'httponly' => true,
                'secure' => 'auto',
                'samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
            $method = new \ReflectionMethod(SessionMiddleware::class, 'applySessionCookieIni');
            $method->invoke($middleware);

            $this->assertSame('1', ini_get('session.cookie_httponly'));
            $this->assertSame('1', ini_get('session.cookie_secure'));
            $this->assertSame('Lax', ini_get('session.cookie_samesite'));
            $this->assertSame('1', ini_get('session.use_strict_mode'));
        } finally {
            foreach ($saved as $key => $value) {
                if ($value !== false && $value !== '') {
                    ini_set($key, $value);
                } else {
                    ini_restore($key);
                }
            }
            unset($_SERVER['HTTPS']);
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    public function samesite_empty_string_opt_out_skips_the_ini_write(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $savedSameSite = ini_get('session.cookie_samesite');

        try {
            // Sentinel: prove the opt-out leaves the ini untouched rather
            // than writing an empty value over it.
            ini_set('session.cookie_samesite', 'Strict');

            $middleware = new SessionMiddleware($repository, null, null, [
                'samesite' => '',
            ]);
            $method = new \ReflectionMethod(SessionMiddleware::class, 'applySessionCookieIni');
            $method->invoke($middleware);

            $this->assertSame(
                'Strict',
                ini_get('session.cookie_samesite'),
                'An explicit samesite => \'\' opt-out must skip the ini write entirely.',
            );
        } finally {
            if ($savedSameSite !== false && $savedSameSite !== '') {
                ini_set('session.cookie_samesite', $savedSameSite);
            } else {
                ini_restore('session.cookie_samesite');
            }
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    public function secure_auto_rejects_forwarded_proto_from_untrusted_ip(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $savedSecure = ini_get('session.cookie_secure');
        try {
            unset($_SERVER['HTTPS']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $_SERVER['REMOTE_ADDR'] = '192.168.1.99';
            $middleware = new SessionMiddleware($repository, null, null, [
                'secure' => 'auto',
            ], ['10.0.0.1']);
            $method = new \ReflectionMethod(SessionMiddleware::class, 'applySessionCookieIni');
            $method->invoke($middleware);

            $this->assertSame('0', ini_get('session.cookie_secure'));
        } finally {
            if ($savedSecure !== false && $savedSecure !== '') {
                ini_set('session.cookie_secure', $savedSecure);
            } else {
                ini_restore('session.cookie_secure');
            }
            unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR']);
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    public function secure_auto_respects_x_forwarded_proto(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $savedSecure = ini_get('session.cookie_secure');
        try {
            unset($_SERVER['HTTPS']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
            $middleware = new SessionMiddleware($repository, null, null, [
                'secure' => 'auto',
            ], ['10.0.0.1']);
            $method = new \ReflectionMethod(SessionMiddleware::class, 'applySessionCookieIni');
            $method->invoke($middleware);

            $this->assertSame('1', ini_get('session.cookie_secure'));
        } finally {
            if ($savedSecure !== false && $savedSecure !== '') {
                ini_set('session.cookie_secure', $savedSecure);
            } else {
                ini_restore('session.cookie_secure');
            }
            unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR']);
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    public function applies_secure_session_cookie_defaults_when_unconfigured(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $keys = [
            'session.cookie_httponly',
            'session.cookie_samesite',
            'session.use_strict_mode',
        ];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = ini_get($key);
        }

        try {
            // Prove the starting state is insecure so the assertions are meaningful.
            ini_set('session.cookie_httponly', '0');
            ini_set('session.cookie_samesite', '');
            ini_set('session.use_strict_mode', '0');

            // No fourth arg => $sessionCookieOptions is null (default config).
            $middleware = new SessionMiddleware($repository);
            $method = new \ReflectionMethod(SessionMiddleware::class, 'applySessionCookieIni');
            $method->invoke($middleware);

            $this->assertSame('1', ini_get('session.cookie_httponly'), 'HttpOnly must be on by default');
            $this->assertSame('Lax', ini_get('session.cookie_samesite'), 'SameSite must default to Lax');
            $this->assertSame('1', ini_get('session.use_strict_mode'), 'Strict session-id mode must be on by default');
        } finally {
            foreach ($saved as $key => $value) {
                if ($value !== false && $value !== '') {
                    ini_set($key, $value);
                } else {
                    ini_restore($key);
                }
            }
        }
    }

    /**
     * Overridability: explicit config wins over the hardened defaults, while
     * un-overridden keys still receive their secure default.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function explicit_session_cookie_options_override_secure_defaults(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $keys = [
            'session.cookie_httponly',
            'session.cookie_samesite',
            'session.use_strict_mode',
        ];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = ini_get($key);
        }

        try {
            ini_set('session.use_strict_mode', '0');

            $middleware = new SessionMiddleware($repository, null, null, [
                'httponly' => false,
                'samesite' => 'Strict',
                // use_strict_mode intentionally omitted -> default (true) applies.
            ]);
            $method = new \ReflectionMethod(SessionMiddleware::class, 'applySessionCookieIni');
            $method->invoke($middleware);

            $this->assertSame('0', ini_get('session.cookie_httponly'), 'explicit httponly=false must override the default');
            $this->assertSame('Strict', ini_get('session.cookie_samesite'), 'explicit samesite must override the Lax default');
            $this->assertSame('1', ini_get('session.use_strict_mode'), 'omitted key must still receive its secure default');
        } finally {
            foreach ($saved as $key => $value) {
                if ($value !== false && $value !== '') {
                    ini_set($key, $value);
                } else {
                    ini_restore($key);
                }
            }
        }
    }
}
