<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Middleware\EngagementResponsePrivacyMiddleware;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Engagement\Tests\Fixtures\ServiceEnvironment;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\Middleware\CsrfMiddleware;

require_once dirname(__DIR__, 3) . '/engagement/tests/Fixtures/ServiceEnvironment.php';

/** Source-fixture proof of compiled engagement route execution with real service storage. */
#[CoversNothing]
final class EngagementCompiledRouteTest extends TestCase
{
    protected function tearDown(): void
    {
        \Waaseyaa\Entity\EntityReadRuntime::installGuard(null);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    #[Test]
    public function compiled_routes_dispatch_real_service_operations_through_both_sql_layouts(): void
    {
        foreach ([false, true] as $columnLayout) {
            $environment = new ServiceEnvironment($columnLayout);
            $provider = new ApiServiceProvider();
            $provider->setKernelServices(new class($environment->service) implements KernelServicesInterface {
                public function __construct(private readonly EngagementService $service) {}

                public function get(string $abstract): ?object
                {
                    return $abstract === EngagementService::class ? $this->service : null;
                }
            });
            $provider->register();
            $context = new RouteContributionContext(ApiServiceProvider::class, 0, capabilities: [
                'api.route.content_search' => false,
                'api.route.mcp' => false,
                'api.route.catalog' => false,
                'api.route.ai_catalog' => false,
                'api.route.engagement' => true,
            ]);
            $definitions = iterator_to_array($provider->routeDefinitions($context));
            $engagementDefinitions = array_values(array_filter(
                $definitions,
                static fn(RouteDefinition $definition): bool => str_starts_with($definition->name, 'api.engagement.'),
            ));
            self::assertCount(11, $engagementDefinitions);

            $router = new WaaseyaaRouter(snapshot: new RouteSnapshot($definitions, []));
            $principal = $environment->alice;
            $root = $environment->rootReference;
            $csrfToken = 'compiled-engagement-csrf';
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['_csrf_token'] = $csrfToken;

            $summary = $this->dispatch($provider, $router, $definitions, Request::create(
                '/engagement/v1/targets/' . $root->type . '/' . $root->id . '/summary', 'GET',
            ), $principal);
            self::assertSame(200, $summary->getStatusCode());
            self::assertSame('no-store, private', $summary->headers->get('Cache-Control'));
            self::assertSame(0, $this->data($summary)['likeCount']);

            $blockedKey = $this->dispatch($provider, $router, $definitions, $this->jsonRequest(
                '/engagement/v1/targets/' . $root->type . '/' . $root->id . '/comment-keys', 'POST', [], null,
            ), $principal);
            self::assertSame(403, $blockedKey->getStatusCode());
            self::assertSame('no-store, private', $blockedKey->headers->get('Cache-Control'));

            $keyResponse = $this->dispatch($provider, $router, $definitions, $this->jsonRequest(
                '/engagement/v1/targets/' . $root->type . '/' . $root->id . '/comment-keys', 'POST', [], $csrfToken,
            ), $principal);
            self::assertSame(200, $keyResponse->getStatusCode());
            $key = $this->data($keyResponse)['submissionKey'];
            self::assertIsString($key);

            $created = $this->dispatch($provider, $router, $definitions, $this->jsonRequest(
                '/engagement/v1/targets/' . $root->type . '/' . $root->id . '/comments',
                'POST', ['text' => 'compiled route comment', 'submissionKey' => $key], $csrfToken,
            ), $principal);
            self::assertSame(201, $created->getStatusCode());
            self::assertSame('no-store, private', $created->headers->get('Cache-Control'));
            $comment = $this->data($created);

            $replyKeyResponse = $this->dispatch($provider, $router, $definitions, $this->jsonRequest(
                '/engagement/v1/targets/' . $root->type . '/' . $root->id . '/comment-keys',
                'POST', ['parentUuid' => $comment['uuid']], $csrfToken,
            ), $principal);
            self::assertSame(200, $replyKeyResponse->getStatusCode());
            $reply = $this->dispatch($provider, $router, $definitions, $this->jsonRequest(
                '/engagement/v1/targets/' . $root->type . '/' . $root->id . '/comments',
                'POST', ['text' => 'compiled route reply', 'submissionKey' => $this->data($replyKeyResponse)['submissionKey'], 'parentUuid' => $comment['uuid']], $csrfToken,
            ), $principal);
            self::assertSame(201, $reply->getStatusCode());

            $show = $this->dispatch($provider, $router, $definitions, Request::create(
                '/engagement/v1/comments/' . $comment['uuid'], 'GET',
            ), $principal);
            self::assertSame(200, $show->getStatusCode());
            self::assertSame('compiled route comment', $this->data($show)['text']);

            $replies = $this->dispatch($provider, $router, $definitions, Request::create(
                '/engagement/v1/comments/' . $comment['uuid'] . '/replies', 'GET',
            ), $principal);
            self::assertSame(200, $replies->getStatusCode());
            self::assertSame('compiled route reply', $this->data($replies)[0]['text']);
        }
    }

    /** @param list<RouteDefinition> $definitions */
    private function dispatch(ApiServiceProvider $provider, WaaseyaaRouter $router, array $definitions, Request $request, AuthorizationPrincipal $principal): Response
    {
        $matched = $router->matchRequest($request);
        $request->attributes->add($matched);
        $route = $router->getRouteCollection()->get($matched['_route']);
        self::assertNotNull($route);
        $request->attributes->set('_route_object', $route);
        $request->attributes->set('_authorization_principal', $principal);
        $request->attributes->set('_account', $principal);

        $definition = current(array_filter(
            $definitions,
            static fn(RouteDefinition $candidate): bool => $candidate->name === $matched['_route'],
        ));
        self::assertInstanceOf(RouteDefinition::class, $definition);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        $controller = new RouteHandlerResolver($definition, $services)->resolveMatched();
        $request->attributes->set('_controller', $controller);

        $terminal = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new ControllerDispatcher([])->dispatch($request);
            }
        };
        $csrf = new CsrfMiddleware();
        $withCsrf = new class($csrf, $terminal) implements HttpHandlerInterface {
            public function __construct(private readonly CsrfMiddleware $csrf, private readonly HttpHandlerInterface $next) {}
            public function handle(Request $request): Response { return $this->csrf->process($request, $this->next); }
        };
        return (new EngagementResponsePrivacyMiddleware())->process($request, $withCsrf);
    }

    /** @param array<string, mixed> $payload */
    private function jsonRequest(string $path, string $method, array $payload, ?string $csrfToken): Request
    {
        $request = Request::create($path, $method, server: [], content: json_encode((object) $payload, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        if ($csrfToken !== null) {
            $request->headers->set('X-CSRF-Token', $csrfToken);
        }
        return $request;
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $payload, (string) $response->getContent());
        return $payload['data'];
    }
}
