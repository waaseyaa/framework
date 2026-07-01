<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\Middleware\BearerAuthMiddleware;
use Waaseyaa\User\User;

#[CoversClass(BearerAuthMiddleware::class)]
final class BearerAuthMiddlewareTest extends TestCase
{
    #[Test]
    public function skips_when_no_bearer_header(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->never())->method('find');

        $middleware = new BearerAuthMiddleware($repository, 'secret', ['api-key' => 7]);
        $request = Request::create('/test');

        $captured = null;
        $next = new class($captured) implements HttpHandlerInterface {
            public function __construct(private mixed &$captured) {}
            public function handle(Request $request): Response
            {
                $this->captured = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertNull($captured);
    }

    #[Test]
    public function resolves_account_from_api_key_token(): void
    {
        $user = new User(['uid' => 7, 'name' => 'machine']);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('7')
            ->willReturn($user);

        $middleware = new BearerAuthMiddleware($repository, '', ['api-key' => 7]);
        $request = Request::create('/test');
        $request->headers->set('Authorization', 'Bearer api-key');

        $captured = null;
        $next = new class($captured) implements HttpHandlerInterface {
            public function __construct(private mixed &$captured) {}
            public function handle(Request $request): Response
            {
                $this->captured = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(User::class, $captured);
        $this->assertSame(7, $captured->id());
    }

    #[Test]
    public function resolves_account_from_valid_jwt(): void
    {
        $secret = 'jwt-secret';
        $token = $this->createJwt($secret, ['uid' => 42, 'exp' => time() + 3600]);
        $user = new User(['uid' => 42, 'name' => 'jwt-user']);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('42')
            ->willReturn($user);

        $middleware = new BearerAuthMiddleware($repository, $secret, []);
        $request = Request::create('/test');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $captured = null;
        $next = new class($captured) implements HttpHandlerInterface {
            public function __construct(private mixed &$captured) {}
            public function handle(Request $request): Response
            {
                $this->captured = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(User::class, $captured);
        $this->assertSame(42, $captured->id());
    }

    #[Test]
    public function falls_back_to_anonymous_for_invalid_bearer_token(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->never())->method('find');

        $middleware = new BearerAuthMiddleware($repository, 'secret', []);
        $request = Request::create('/test');
        $request->headers->set('Authorization', 'Bearer definitely-invalid');

        $captured = null;
        $next = new class($captured) implements HttpHandlerInterface {
            public function __construct(private mixed &$captured) {}
            public function handle(Request $request): Response
            {
                $this->captured = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(AnonymousUser::class, $captured);
    }

    #[Test]
    public function falls_back_to_anonymous_when_resolved_user_not_found(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('999')
            ->willReturn(null);

        $middleware = new BearerAuthMiddleware($repository, '', ['api-key' => 999]);
        $request = Request::create('/test');
        $request->headers->set('Authorization', 'Bearer api-key');

        $captured = null;
        $next = new class($captured) implements HttpHandlerInterface {
            public function __construct(private mixed &$captured) {}
            public function handle(Request $request): Response
            {
                $this->captured = $request->attributes->get('_account');
                return new Response('ok');
            }
        };

        $middleware->process($request, $next);

        $this->assertInstanceOf(AnonymousUser::class, $captured);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createJwt(string $secret, array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $secret, true);
        $encodedSignature = $this->base64UrlEncode($signature);

        return "{$encodedHeader}.{$encodedPayload}.{$encodedSignature}";
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
