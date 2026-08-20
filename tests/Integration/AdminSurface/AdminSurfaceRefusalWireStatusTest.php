<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AdminSurface;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Wire-status contract for admin-surface refusals (#2161).
 *
 * Before this test, every `admin_surface.*` endpoint answered a refusal with
 * HTTP 200 and carried the real status only inside the response envelope
 * (`{"ok": false, "error": {"status": 403, ...}}`). Clients and monitoring
 * that key on the status line read a denied write as a success.
 *
 * The defect was never action-specific. All five `AbstractAdminSurfaceHost`
 * `handle*` methods return the same flat envelope, every one of them can emit
 * `ok:false`, and all five land on the same
 * `ControllerDispatcher::handleCallable()` default of `statusCode ?? 200`.
 *
 * Everything here is asserted through the *registered route closure* driven by
 * a real `ControllerDispatcher` — the same composition the kernel runs — rather
 * than by reaching into the provider's promotion helper. The helper is an
 * implementation detail; the wire status is the contract.
 *
 * `admin_surface.page_builder.*` is deliberately out of scope: those routes go
 * through `PageBuilderSurfaceHostInterface`, whose handlers are typed as bare
 * `array<string, mixed>`, and whether they carry this envelope at all is a
 * separate question.
 */
#[CoversNothing]
final class AdminSurfaceRefusalWireStatusTest extends TestCase
{
    /**
     * Every `admin_surface.*` route, with the parameters its closure expects.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function allFiveRoutes(): array
    {
        return [
            'session' => ['admin_surface.session', [], 'GET'],
            'catalog' => ['admin_surface.catalog', [], 'GET'],
            'list' => ['admin_surface.list', ['type' => 'article'], 'GET'],
            'get' => ['admin_surface.get', ['type' => 'article', 'id' => '1'], 'GET'],
            'action' => ['admin_surface.action', ['type' => 'article', 'action' => 'create'], 'POST'],
        ];
    }

    /**
     * Refusal statuses a host may legitimately return across the 4xx/5xx range.
     *
     * @return array<string, array{0: int}>
     */
    public static function refusalStatuses(): array
    {
        return [
            'bad request' => [400],
            'unauthorized' => [401],
            'forbidden' => [403],
            'not found' => [404],
            'conflict' => [409],
            'unprocessable' => [422],
            'server error' => [500],
            'unavailable' => [503],
            'lower boundary' => [400],
            'upper boundary' => [599],
        ];
    }

    /**
     * Envelopes whose `error.status` is not a promotable 400-599 integer.
     *
     * A host subclass may override `handle*` and return a hand-built array, so
     * these shapes are reachable in production. Handing a bad value straight to
     * the dispatcher would reach the `Response` constructor and turn a clean
     * refusal into a 500, so they must fall through to today's behaviour.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unpromotableEnvelopes(): array
    {
        return [
            'no error key at all' => [
                ['ok' => false],
            ],
            'error without a status' => [
                ['ok' => false, 'error' => ['title' => 'Forbidden']],
            ],
            'status as a numeric string' => [
                ['ok' => false, 'error' => ['status' => '403', 'title' => 'Forbidden']],
            ],
            'status as a non-numeric string' => [
                ['ok' => false, 'error' => ['status' => 'forbidden', 'title' => 'Forbidden']],
            ],
            'status as null' => [
                ['ok' => false, 'error' => ['status' => null, 'title' => 'Forbidden']],
            ],
            'status as a float' => [
                ['ok' => false, 'error' => ['status' => 403.0, 'title' => 'Forbidden']],
            ],
            'status below the 4xx floor' => [
                ['ok' => false, 'error' => ['status' => 399, 'title' => 'Redirect']],
            ],
            'status above the 5xx ceiling' => [
                ['ok' => false, 'error' => ['status' => 600, 'title' => 'Nonsense']],
            ],
            'success status smuggled into a refusal' => [
                ['ok' => false, 'error' => ['status' => 200, 'title' => 'Not really an error']],
            ],
        ];
    }

    #[Test]
    #[DataProvider('allFiveRoutes')]
    public function unauthenticatedRefusalPromotesTo401OnEveryRoute(
        string $routeName,
        array $routeParams,
        string $method,
    ): void {
        // A null session short-circuits every handle* method to a 401 envelope,
        // which makes it the one refusal reachable on all five routes at once.
        $response = $this->dispatch($this->host(session: null), $routeName, $routeParams, $method);

        self::assertSame(
            401,
            $response->getStatusCode(),
            sprintf('%s must answer an unauthenticated refusal with a real 401 status line', $routeName),
        );
        self::assertFalse($this->decode($response)['ok']);
        self::assertSame(401, $this->decode($response)['error']['status']);
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertBodyIs(
            $this->callHostDirectly($this->host(session: null), $routeName, $routeParams, $method),
            $response,
        );
    }

    /**
     * Sheg #98 builds an authenticated toolbar/account menu on top of this:
     * the SPA bootstrap has to be able to tell "not signed in" from the status
     * line, not only from the envelope.
     */
    #[Test]
    public function sessionRefusalProducesARealHttp401(): void
    {
        $response = $this->dispatch($this->host(session: null), 'admin_surface.session');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[DataProvider('refusalStatuses')]
    public function anyValidRefusalStatusIsPromotedToTheWire(int $status): void
    {
        $host = $this->host(
            session: $this->session(),
            outcome: AdminSurfaceResultData::error($status, 'Refused'),
        );

        $response = $this->dispatch(
            $host,
            'admin_surface.action',
            ['type' => 'article', 'action' => 'create'],
            'POST',
        );

        self::assertSame($status, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('allFiveRoutes')]
    public function successfulEnvelopesStayHttp200(
        string $routeName,
        array $routeParams,
        string $method,
    ): void {
        $response = $this->dispatch($this->host(session: $this->session()), $routeName, $routeParams, $method);

        self::assertSame(200, $response->getStatusCode(), $routeName . ' must not disturb success responses');
        self::assertTrue($this->decode($response)['ok']);
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    #[Test]
    #[DataProvider('allFiveRoutes')]
    public function successfulEnvelopesAreByteIdenticalToTheHostOutput(
        string $routeName,
        array $routeParams,
        string $method,
    ): void {
        $host = $this->host(session: $this->session());

        $dispatched = $this->dispatch($host, $routeName, $routeParams, $method);
        $direct = $this->callHostDirectly($host, $routeName, $routeParams, $method);

        $this->assertBodyIs($direct, $dispatched, $routeName . ' must serialise the host envelope unchanged');
    }

    #[Test]
    #[DataProvider('refusalStatuses')]
    public function promotionLeavesTheJsonEnvelopeUnchanged(int $status): void
    {
        $host = $this->host(
            session: $this->session(),
            outcome: AdminSurfaceResultData::error($status, 'Refused', 'Detail preserved.'),
        );
        $routeParams = ['type' => 'article', 'action' => 'create'];

        $dispatched = $this->dispatch($host, 'admin_surface.action', $routeParams, 'POST');

        // The status moves to the wire; the body it came from does not change.
        $this->assertBodyIs(
            $this->callHostDirectly($host, 'admin_surface.action', $routeParams, 'POST'),
            $dispatched,
        );
        self::assertSame($status, $this->decode($dispatched)['error']['status']);
        self::assertSame('Detail preserved.', $this->decode($dispatched)['error']['detail']);
    }

    #[Test]
    #[DataProvider('unpromotableEnvelopes')]
    public function unpromotableRefusalStatusRetainsExistingBehaviour(array $envelope): void
    {
        $host = $this->host(session: $this->session(), rawActionEnvelope: $envelope);

        $response = $this->dispatch(
            $host,
            'admin_surface.action',
            ['type' => 'article', 'action' => 'create'],
            'POST',
        );

        self::assertSame(200, $response->getStatusCode(), 'An unpromotable status must not change the status line');
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertBodyIs($envelope, $response, 'An unpromotable envelope must pass through untouched');
    }

    /**
     * Dispatch a registered route closure through a real `ControllerDispatcher`.
     *
     * @param array<string, string> $routeParams
     */
    private function dispatch(
        AbstractAdminSurfaceHost $host,
        string $routeName,
        array $routeParams = [],
        string $method = 'GET',
    ): Response {
        $router = new WaaseyaaRouter(new RequestContext('', $method));
        AdminSurfaceServiceProvider::registerRoutes($router, $host);

        $route = $router->getRouteCollection()->get($routeName);
        self::assertNotNull($route, sprintf('Route %s must be registered', $routeName));

        $request = Request::create('/', $method);
        $request->attributes->set('_controller', $route->getDefault('_controller'));
        foreach ($routeParams as $name => $value) {
            $request->attributes->set($name, $value);
        }

        return new ControllerDispatcher([])->dispatch($request);
    }

    /**
     * Call the host handler the route closure wraps, for envelope comparison.
     *
     * @param  array<string, string> $routeParams
     * @return array<string, mixed>
     */
    private function callHostDirectly(
        AbstractAdminSurfaceHost $host,
        string $routeName,
        array $routeParams,
        string $method,
    ): array {
        $request = Request::create('/', $method);

        return match ($routeName) {
            'admin_surface.session' => $host->handleSession($request),
            'admin_surface.catalog' => $host->handleCatalog($request),
            'admin_surface.list' => $host->handleList($request, $routeParams['type']),
            'admin_surface.get' => $host->handleGet($request, $routeParams['type'], $routeParams['id']),
            'admin_surface.action' => $host->handleAction($request, $routeParams['type'], $routeParams['action']),
            default => self::fail('Unhandled route ' . $routeName),
        };
    }

    /**
     * Assert the response body is byte-for-byte the JSON of `$expected`.
     *
     * Compared as raw bytes rather than decoded arrays on purpose: decoding
     * normalises away real distinctions (an empty JSON object round-trips to an
     * empty PHP array under associative decoding, `403.0` to `403`), and those
     * are exactly the differences a serialisation regression would hide. The
     * bytes mirror the compact `JsonResponse` emitted at the Admin Surface
     * route boundary, not Foundation's JSON:API formatter.
     *
     * @param array<string, mixed> $expected
     */
    private function assertBodyIs(array $expected, Response $response, string $message = ''): void
    {
        self::assertSame(
            new \Symfony\Component\HttpFoundation\JsonResponse($expected)->getContent(),
            $response->getContent(),
            $message,
        );
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function session(): AdminSurfaceSessionData
    {
        return new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['admin'],
            policies: [],
        );
    }

    /**
     * @param array<string, mixed>|null $rawActionEnvelope Bypasses AdminSurfaceResultData so
     *   malformed envelopes — reachable when a subclass overrides handleAction — can be tested.
     */
    private function host(
        ?AdminSurfaceSessionData $session,
        ?AdminSurfaceResultData $outcome = null,
        ?array $rawActionEnvelope = null,
    ): AbstractAdminSurfaceHost {
        return new class ($session, $outcome, $rawActionEnvelope) extends AbstractAdminSurfaceHost {
            /** @param array<string, mixed>|null $rawActionEnvelope */
            public function __construct(
                private readonly ?AdminSurfaceSessionData $session,
                private readonly ?AdminSurfaceResultData $outcome,
                private readonly ?array $rawActionEnvelope,
            ) {}

            public function resolveSession(Request $request): ?AdminSurfaceSessionData
            {
                return $this->session;
            }

            public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
            {
                return new CatalogBuilder();
            }

            public function list(string $type, SurfaceQuery|array $query = []): AdminSurfaceResultData
            {
                return $this->outcome ?? AdminSurfaceResultData::success(['entities' => [], 'total' => 0]);
            }

            public function get(string $type, string $id): AdminSurfaceResultData
            {
                return $this->outcome ?? AdminSurfaceResultData::success(['type' => $type, 'id' => $id]);
            }

            public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
            {
                return $this->outcome ?? AdminSurfaceResultData::success(['action' => $action]);
            }

            public function handleAction(Request $request, string $type, string $action): array
            {
                return $this->rawActionEnvelope ?? parent::handleAction($request, $type, $action);
            }
        };
    }
}
