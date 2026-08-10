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
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\ContextualAccountPrincipalFactoryInterface;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Community\CommunityContext;
use Waaseyaa\Foundation\Community\CommunityMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Testing\Factory\AuthorizationPrincipalFactory;
use Waaseyaa\User\Middleware\SessionMiddleware;

final class FieldReadContextMiddlewareTest extends TestCase
{
    #[Test]
    public function production_order_is_identity_then_field_context_then_route_authorization(): void
    {
        $priority = static function (string $class): int {
            $attributes = new \ReflectionClass($class)->getAttributes(AsMiddleware::class);
            return $attributes[0]->newInstance()->priority;
        };

        self::assertGreaterThan($priority(FieldReadContextMiddleware::class), $priority(SessionMiddleware::class));
        self::assertGreaterThan($priority(AuthorizationMiddleware::class), $priority(FieldReadContextMiddleware::class));
    }

    #[Test]
    public function generic_account_snapshot_is_refused_instead_of_inventing_permissions(): void
    {
        $account = new class implements AccountInterface {
            public function id(): int|string
            {
                return 42;
            }
            public function hasPermission(string $permission): bool
            {
                return true;
            }
            public function getRoles(): array
            {
                return ['member'];
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
        };

        $this->expectException(\LogicException::class);
        new AccountPrincipalFactory()->fromAccount($account);
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
        $handler = new class ($scope, $accountContext) implements HttpHandlerInterface {
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
    public function configured_community_is_normalized_into_the_immutable_principal_during_dispatch(): void
    {
        $community = new CommunityContext();
        $community->set('configured-community');
        $scope = new AccountFieldReadScope();
        $principalFactory = new class implements ContextualAccountPrincipalFactoryInterface {
            public function fromAccount(AccountInterface $account): AuthorizationPrincipalInterface
            {
                return $this->fromAccountInContext($account, null, null);
            }

            public function fromAccountInContext(AccountInterface $account, ?string $tenantId, ?string $communityId): AuthorizationPrincipalInterface
            {
                return new AuthorizationPrincipal(
                    accountId: $account->id(),
                    authenticated: $account->isAuthenticated(),
                    roles: $account->getRoles(),
                    permissions: [],
                    claimsGeneration: 'community-pipeline-test',
                    tenantId: $tenantId,
                    communityId: $communityId,
                );
            }
        };
        $middleware = new FieldReadContextMiddleware($principalFactory, $scope);
        $request = Request::create('/admin/extension');
        $request->attributes->set('_account', new class implements AccountInterface {
            public function id(): int|string
            {
                return 42;
            }
            public function hasPermission(string $permission): bool
            {
                return false;
            }
            public function getRoles(): array
            {
                return ['administrator'];
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
        });
        $handler = new class ($community, $scope) implements HttpHandlerInterface {
            public function __construct(
                private readonly CommunityContext $community,
                private readonly AccountFieldReadScope $scope,
            ) {}

            public function handle(Request $request): Response
            {
                TestCase::assertSame('configured-community', $request->attributes->get('_community_id'));
                TestCase::assertSame('configured-community', $this->community->get());
                TestCase::assertSame('configured-community', $this->scope->current()?->communityId());
                TestCase::assertSame(
                    $this->scope->current(),
                    $request->attributes->get('_authorization_principal'),
                );

                return new Response('ok');
            }
        };

        $response = new HttpPipeline([
            new CommunityMiddleware($community),
            $middleware,
        ])->handle($request, $handler);

        self::assertSame('ok', $response->getContent());
        self::assertTrue($community->isActive());
        self::assertSame('configured-community', $community->get());
        self::assertNull($scope->current());
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
        $handler = new class ($scope, $seen) implements HttpHandlerInterface {
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
