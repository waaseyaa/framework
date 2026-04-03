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
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\Session\NativeSession;
use Waaseyaa\User\User;

#[CoversClass(SessionMiddleware::class)]
final class SessionMiddlewareTest extends TestCase
{
    #[Test]
    public function sets_anonymous_user_when_no_session(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->never())->method('load');

        $middleware = new SessionMiddleware($storage);
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

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())
            ->method('load')
            ->with(42)
            ->willReturn($user);

        $middleware = new SessionMiddleware($storage);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

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
    public function falls_back_to_anonymous_when_user_not_found(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())
            ->method('load')
            ->with(999)
            ->willReturn(null);

        $middleware = new SessionMiddleware($storage);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 999]);

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
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())
            ->method('load')
            ->with(42)
            ->willThrowException(new \RuntimeException('Database unavailable'));

        $middleware = new SessionMiddleware($storage);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

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
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->never())->method('load');

        $middleware = new SessionMiddleware($storage, $devAccount);
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

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())
            ->method('load')
            ->with(42)
            ->willReturn($user);

        $middleware = new SessionMiddleware($storage, $devAccount);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

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
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())
            ->method('load')
            ->with(999)
            ->willReturn(null);

        $middleware = new SessionMiddleware($storage, $devAccount);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 999]);

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
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())
            ->method('load')
            ->with(42)
            ->willThrowException(new \RuntimeException('Database unavailable'));

        $middleware = new SessionMiddleware($storage, $devAccount);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

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
        $storage = $this->createMock(EntityStorageInterface::class);
        $middleware = new SessionMiddleware($storage);
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
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->never())->method('load');

        $middleware = new SessionMiddleware($storage);
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
        $storage = $this->createMock(EntityStorageInterface::class);
        $middleware = new SessionMiddleware($storage);
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
    public function does_not_replace_existing_session(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $middleware = new SessionMiddleware($storage);
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
    #[RunInSeparateProcess]
    public function applies_session_cookie_ini_when_configured(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
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
            $middleware = new SessionMiddleware($storage, null, null, [
                'httponly' => true,
                'secure' => 'auto',
                'samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
            $method = new \ReflectionMethod(SessionMiddleware::class, 'applySessionCookieIni');
            $method->setAccessible(true);
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
    public function secure_auto_rejects_forwarded_proto_from_untrusted_ip(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $savedSecure = ini_get('session.cookie_secure');
        try {
            unset($_SERVER['HTTPS']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $_SERVER['REMOTE_ADDR'] = '192.168.1.99';
            $middleware = new SessionMiddleware($storage, null, null, [
                'secure' => 'auto',
            ], ['10.0.0.1']);
            $method = new \ReflectionMethod(SessionMiddleware::class, 'applySessionCookieIni');
            $method->setAccessible(true);
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
        $storage = $this->createMock(EntityStorageInterface::class);
        $savedSecure = ini_get('session.cookie_secure');
        try {
            unset($_SERVER['HTTPS']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
            $middleware = new SessionMiddleware($storage, null, null, [
                'secure' => 'auto',
            ], ['10.0.0.1']);
            $method = new \ReflectionMethod(SessionMiddleware::class, 'applySessionCookieIni');
            $method->setAccessible(true);
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
}
