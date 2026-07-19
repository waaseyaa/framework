<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\User\Middleware\SessionMiddleware;

final class FieldReadContextMiddlewareTest extends TestCase
{
    #[Test]
    public function production_order_is_identity_then_field_context_then_route_authorization(): void
    {
        $priority = static function (string $class): int {
            $attributes = (new \ReflectionClass($class))->getAttributes(AsMiddleware::class);
            return $attributes[0]->newInstance()->priority;
        };

        self::assertGreaterThan($priority(FieldReadContextMiddleware::class), $priority(SessionMiddleware::class));
        self::assertGreaterThan($priority(AuthorizationMiddleware::class), $priority(FieldReadContextMiddleware::class));
    }

    #[Test]
    public function generic_account_snapshot_does_not_invent_permissions(): void
    {
        $principal = (new AccountPrincipalFactory())->fromAccount(new class implements AccountInterface {
            public function id(): int|string { return 42; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return ['member']; }
            public function isAuthenticated(): bool { return true; }
        });

        self::assertSame(['member'], $principal->getRoles());
        self::assertFalse($principal->hasPermission('view members'));
    }

    #[Test]
    public function principal_is_installed_for_dispatch_and_cleared_afterward(): void
    {
        $scope = new AccountFieldReadScope();
        $accountContext = new RequestAccountContext();
        $middleware = new FieldReadContextMiddleware(new AccountPrincipalFactory(), $scope, $accountContext);
        $request = Request::create('/members');
        $request->attributes->set('_account', new class implements AccountInterface {
            public function id(): int|string { return 42; }
            public function hasPermission(string $permission): bool { return $permission === 'view members'; }
            public function getRoles(): array { return ['member']; }
            public function isAuthenticated(): bool { return true; }
        });
        $handler = new class($scope, $accountContext) implements HttpHandlerInterface {
            public function __construct(
                private readonly AccountFieldReadScope $scope,
                private readonly RequestAccountContext $accountContext,
            ) {}
            public function handle(Request $request): Response
            {
                TestCase::assertSame(42, $this->scope->current()?->id());
                TestCase::assertSame($this->scope->current(), $this->accountContext->current());

                return new Response('ok');
            }
        };

        self::assertSame('ok', $middleware->process($request, $handler)->getContent());
        self::assertNull($scope->current());
        self::assertNull($accountContext->current());
    }

    #[Test]
    public function deferred_stream_callback_reinstalls_the_principal_and_clears_it_after_send(): void
    {
        $scope = new AccountFieldReadScope();
        $middleware = new FieldReadContextMiddleware(new AccountPrincipalFactory(), $scope);
        $request = Request::create('/members.csv');
        $request->attributes->set('_account', new class implements AccountInterface {
            public function id(): int|string { return 42; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return ['member']; }
            public function isAuthenticated(): bool { return true; }
        });
        $seen = null;
        $handler = new class($scope, $seen) implements HttpHandlerInterface {
            public function __construct(private readonly AccountFieldReadScope $scope, private mixed &$seen) {}
            public function handle(Request $request): Response
            {
                return new StreamedResponse(function (): void {
                    $this->seen = $this->scope->current()?->id();
                });
            }
        };

        $response = $middleware->process($request, $handler);
        self::assertNull($scope->current());
        ob_start();
        $response->sendContent();
        ob_end_clean();
        self::assertSame(42, $seen);
        self::assertNull($scope->current());
    }
}
