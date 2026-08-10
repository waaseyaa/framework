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
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\ContextualAccountPrincipalFactoryInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\Foundation\Community\CommunityContext;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\Testing\Factory\AuthorizationPrincipalFactory;

final class FieldReadContextMiddlewareTest extends TestCase
{
    #[Test]
    public function configured_community_is_copied_to_the_immutable_principal(): void
    {
        $scope = new AccountFieldReadScope();
        $community = new CommunityContext();
        $community->set('community-a');
        $middleware = new FieldReadContextMiddleware($this->contextualFactory(), $scope, null, $community);
        $request = Request::create('/admin/anokii/identity');
        $request->attributes->set('_account', AuthorizationPrincipalFactory::authenticated(id: 42));
        $handler = new class($community) implements HttpHandlerInterface {
            public function __construct(private readonly CommunityContext $community) {}

            public function handle(Request $request): Response
            {
                $principal = $request->attributes->get('_authorization_principal');
                TestCase::assertSame('community-a', $principal->communityId());
                TestCase::assertSame('community-a', $this->community->get());

                return new Response('ok');
            }
        };

        self::assertSame('ok', $middleware->process($request, $handler)->getContent());
    }

    #[Test]
    public function explicit_request_community_takes_precedence_over_the_configured_context(): void
    {
        $scope = new AccountFieldReadScope();
        $community = new CommunityContext();
        $community->set('configured-community');
        $middleware = new FieldReadContextMiddleware($this->contextualFactory(), $scope, null, $community);
        $request = Request::create('/community/route-community/admin');
        $request->attributes->set('community_id', 'route-community');
        $request->attributes->set('_account', AuthorizationPrincipalFactory::authenticated(id: 42));
        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                $principal = $request->attributes->get('_authorization_principal');
                TestCase::assertSame('route-community', $principal->communityId());

                return new Response('ok');
            }
        };

        self::assertSame('ok', $middleware->process($request, $handler)->getContent());
    }

    #[Test]
    public function inactive_context_leaves_the_immutable_principal_unscoped(): void
    {
        $scope = new AccountFieldReadScope();
        $community = new CommunityContext();
        $middleware = new FieldReadContextMiddleware($this->contextualFactory(), $scope, null, $community);
        $request = Request::create('/admin');
        $request->attributes->set('_account', AuthorizationPrincipalFactory::authenticated(id: 42));
        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                $principal = $request->attributes->get('_authorization_principal');
                TestCase::assertNull($principal->communityId());

                return new Response('ok');
            }
        };

        self::assertSame('ok', $middleware->process($request, $handler)->getContent());
    }

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
    public function generic_account_snapshot_is_refused_instead_of_inventing_permissions(): void
    {
        $account = new class implements AccountInterface {
            public function id(): int|string { return 42; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return ['member']; }
            public function isAuthenticated(): bool { return true; }
        };

        $this->expectException(\LogicException::class);
        (new AccountPrincipalFactory())->fromAccount($account);
    }

    #[Test]
    public function principal_is_installed_for_dispatch_and_cleared_afterward(): void
    {
        $scope = new AccountFieldReadScope();
        $accountContext = new RequestAccountContext();
        $middleware = new FieldReadContextMiddleware(new AccountPrincipalFactory(), $scope, $accountContext);
        $request = Request::create('/members');
        $request->attributes->set('_account', AuthorizationPrincipalFactory::authenticated(
            id: 42,
            roles: ['member'],
            permissions: ['view members'],
        ));
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
        $request->attributes->set('_account', AuthorizationPrincipalFactory::authenticated(
            id: 42,
            roles: ['member'],
        ));
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

    private function contextualFactory(): ContextualAccountPrincipalFactoryInterface
    {
        return new class implements ContextualAccountPrincipalFactoryInterface {
            public function fromAccount(AccountInterface $account): AuthorizationPrincipalInterface
            {
                return $this->fromAccountInContext($account, null, null);
            }

            public function fromAccountInContext(
                AccountInterface $account,
                ?string $tenantId,
                ?string $communityId,
            ): AuthorizationPrincipalInterface {
                return new AuthorizationPrincipal(
                    $account->id(),
                    $account->isAuthenticated(),
                    $account->getRoles(),
                    [],
                    'context-test',
                    $tenantId,
                    $communityId,
                );
            }
        };
    }
}
