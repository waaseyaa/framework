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
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AdminSurface\AdminSurfaceRoutePaths;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\PageBuilder\GenericPageBuilderSurfaceHost;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceRequest;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGatewayInterface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurface;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Wire-status contract for page-builder refusals (#2409).
 *
 * #2161 / PR #2408 promoted the five `admin_surface.*` routes and explicitly
 * left the seven `admin_surface.page_builder.*` routes alone, because they go
 * through `PageBuilderSurfaceHostInterface`, whose handlers are typed as bare
 * `array<string, mixed>`. The audit that exclusion deferred is this file.
 *
 * The answer is that the seven carry the *same* envelope. Every one of them is
 * a one-line delegation to `GenericPageBuilderSurfaceHost::execute()`, and every
 * refusal it can produce is an `AdminSurfaceResultData::error()` array — so all
 * seven landed on `ControllerDispatcher::handleCallable()`'s `statusCode ?? 200`
 * default and shipped a 401/403/404/409/422/428/501 as HTTP 200.
 *
 * The one thing that must not change is the body. Unlike the five, these routes
 * answer with Foundation's JSON:API media type and pretty-printing, so every
 * case here pins the response bytes as well as the status line.
 *
 * Everything is asserted through the *registered route closure* driven by a real
 * `ControllerDispatcher` — the same composition the kernel runs — never by
 * reaching into the provider's promotion helper.
 */
#[CoversNothing]
final class PageBuilderRefusalWireStatusTest extends TestCase
{
    /** A decodable command, so a refusal is reached for the reason under test. */
    private const array WIRE_COMMAND = ['type' => 'remove_block', 'block_id' => 'blk_intro'];

    /**
     * Every `admin_surface.page_builder.*` route, with the parameters and body
     * its closure expects.
     *
     * The body matters only for the real-host cases: `handleCommand`,
     * `handlePreview`, and `handleRestore` parse it before they reach the
     * surface, so an empty body would refuse with 400 for the wrong reason.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: string, 3: string}>
     */
    public static function allSevenRoutes(): array
    {
        $page = ['surface' => 'pages', 'id' => '42'];

        return [
            'definitions' => ['admin_surface.page_builder.definitions', ['surface' => 'pages'], 'GET', ''],
            'draft' => ['admin_surface.page_builder.draft', $page, 'GET', ''],
            'command' => ['admin_surface.page_builder.command', $page, 'POST', self::commandBody()],
            'preview' => ['admin_surface.page_builder.preview', $page, 'POST', '{"expected_entity_revision_id":1}'],
            'history' => ['admin_surface.page_builder.history', $page, 'GET', ''],
            'revision' => ['admin_surface.page_builder.revision', $page + ['revision' => '5'], 'GET', ''],
            'restore' => ['admin_surface.page_builder.restore', $page, 'POST', self::restoreBody()],
        ];
    }

    /**
     * Refusal statuses the page-builder host can reach, plus the range bounds.
     *
     * 401 (no actor), 404 (unknown surface/draft/history), and 403 (denied) are
     * reachable on all seven; 400 on any malformed request; 409 on command,
     * preview, and restore; 422 on command, history, revision, restore, and
     * draft; 428 and 501 only on command. The matrix runs all of them on every
     * route because the promotion is a single shared boundary, not a per-route
     * table — and 599 pins the upper bound of what is promotable at all.
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
            'precondition required' => [428],
            'not implemented' => [501],
            'upper boundary' => [599],
        ];
    }

    /**
     * Envelopes whose `error.status` is not a promotable 400-599 integer.
     *
     * `PageBuilderSurfaceHostInterface` types its handlers as bare
     * `array<string, mixed>`, so a third-party host can return any of these.
     * Handing a bad value to the dispatcher would reach the `Response`
     * constructor and turn a clean refusal into a 500, so each must retain
     * exactly the behaviour it had before the promotion existed.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unpromotableEnvelopes(): array
    {
        return [
            'no error key at all' => [['ok' => false]],
            'error without a status' => [['ok' => false, 'error' => ['title' => 'Forbidden']]],
            'error that is not an array' => [['ok' => false, 'error' => 'forbidden']],
            'status as a numeric string' => [['ok' => false, 'error' => ['status' => '403', 'title' => 'Forbidden']]],
            'status as a non-numeric string' => [['ok' => false, 'error' => ['status' => 'forbidden', 'title' => 'Forbidden']]],
            'status as null' => [['ok' => false, 'error' => ['status' => null, 'title' => 'Forbidden']]],
            'status as a float' => [['ok' => false, 'error' => ['status' => 403.0, 'title' => 'Forbidden']]],
            'status as an array' => [['ok' => false, 'error' => ['status' => [403], 'title' => 'Forbidden']]],
            'status as zero' => [['ok' => false, 'error' => ['status' => 0, 'title' => 'Nothing']]],
            'status well below the 4xx floor' => [['ok' => false, 'error' => ['status' => 99, 'title' => 'Nonsense']]],
            'status just below the 4xx floor' => [['ok' => false, 'error' => ['status' => 399, 'title' => 'Redirect']]],
            'status just above the 5xx ceiling' => [['ok' => false, 'error' => ['status' => 600, 'title' => 'Nonsense']]],
            'status far above the 5xx ceiling' => [['ok' => false, 'error' => ['status' => 1000, 'title' => 'Nonsense']]],
            'success carrying an error status' => [['ok' => true, 'data' => [], 'error' => ['status' => 403]]],
            'ok missing entirely' => [['error' => ['status' => 403, 'title' => 'Forbidden']]],
            'ok as a falsy non-false value' => [['ok' => 0, 'error' => ['status' => 403, 'title' => 'Forbidden']]],
        ];
    }

    #[Test]
    #[DataProvider('allSevenRoutes')]
    public function everyRoutePromotesEveryRefusalStatusToTheWire(
        string $routeName,
        array $routeParams,
        string $method,
        string $body,
    ): void {
        foreach (self::refusalStatuses() as $label => [$status]) {
            $envelope = AdminSurfaceResultData::error($status, 'Refused', 'Detail preserved.')->toArray();
            $response = $this->dispatch($this->host($envelope), $routeName, $routeParams, $method, $body);

            self::assertSame(
                $status,
                $response->getStatusCode(),
                sprintf('%s must answer a %s refusal with a real %d status line', $routeName, $label, $status),
            );
            $this->assertBodyIs($envelope, $response, $routeName . ' must not disturb the ' . $label . ' body');
        }
    }

    /**
     * The whole point of the promotion: the status line moves, the bytes do not.
     */
    #[Test]
    #[DataProvider('refusalStatuses')]
    public function promotionLeavesTheJsonEnvelopeByteIdentical(int $status): void
    {
        $envelope = AdminSurfaceResultData::error(
            $status,
            'Refused',
            'Detail preserved.',
            'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
            saveAdvisories: [[
                'code' => 'layout.section.orphaned',
                'field' => 'sections',
                'severity' => 'warning',
                'message' => 'A section will be left empty.',
                'acknowledgement' => str_repeat('a', 64),
            ]],
        )->toArray();

        $response = $this->dispatch(
            $this->host($envelope),
            'admin_surface.page_builder.command',
            ['surface' => 'pages', 'id' => '42'],
            'POST',
        );

        self::assertSame($status, $response->getStatusCode());
        $this->assertBodyIs($envelope, $response);

        // The transport keys are the dispatcher's, not the envelope's.
        $decoded = $this->decode($response);
        self::assertArrayNotHasKey('statusCode', $decoded);
        self::assertArrayNotHasKey('body', $decoded);
        self::assertSame($status, $decoded['error']['status']);
        self::assertSame('Detail preserved.', $decoded['error']['detail']);
        self::assertSame(
            str_repeat('a', 64),
            $decoded['error']['meta']['save_advisories'][0]['acknowledgement'],
            'The #2475 advisory receipt must survive the promotion verbatim',
        );
    }

    #[Test]
    #[DataProvider('allSevenRoutes')]
    public function successfulEnvelopesStayHttp200WithAnUnchangedBody(
        string $routeName,
        array $routeParams,
        string $method,
        string $body,
    ): void {
        $envelope = AdminSurfaceResultData::success(['definitions' => [], 'revisions' => []])->toArray();

        $response = $this->dispatch($this->host($envelope), $routeName, $routeParams, $method, $body);

        self::assertSame(200, $response->getStatusCode(), $routeName . ' must not disturb success responses');
        self::assertTrue($this->decode($response)['ok']);
        $this->assertBodyIs($envelope, $response, $routeName . ' must serialise the host envelope unchanged');
    }

    #[Test]
    #[DataProvider('unpromotableEnvelopes')]
    public function anUnpromotableStatusRetainsExistingBehaviourOnEveryRoute(array $envelope): void
    {
        foreach (self::allSevenRoutes() as $label => [$routeName, $routeParams, $method, $body]) {
            $response = $this->dispatch($this->host($envelope), $routeName, $routeParams, $method, $body);

            self::assertSame(
                200,
                $response->getStatusCode(),
                sprintf('%s must not move an unpromotable status onto the wire', $label),
            );
            $this->assertBodyIs($envelope, $response, $label . ' must pass an unpromotable envelope through untouched');
        }
    }

    /**
     * A host that hand-builds the dispatcher's own transport keys still wins.
     *
     * `ControllerDispatcher` has always honoured a top-level `statusCode`/`body`
     * pair. An envelope carrying them is not the Admin Surface envelope, so the
     * promotion must not rewrap it — the pre-change response is the contract.
     */
    #[Test]
    public function anEnvelopeCarryingItsOwnTransportKeysIsLeftAlone(): void
    {
        $envelope = [
            'statusCode' => 418,
            'body' => ['ok' => false, 'error' => ['status' => 403, 'title' => 'Hand built']],
        ];

        $response = $this->dispatch(
            $this->host($envelope),
            'admin_surface.page_builder.draft',
            ['surface' => 'pages', 'id' => '42'],
        );

        self::assertSame(418, $response->getStatusCode());
        $this->assertBodyIs($envelope['body'], $response);
    }

    /**
     * Routing, not the host, refuses a malformed revision id.
     *
     * `admin_surface.page_builder.revision` constrains `{revision}` to
     * `[1-9][0-9]*`, so a `0` or `abc` never reaches `handleRevision`. That is a
     * routing 404, and the promotion must not be read as turning it into the
     * host's own 400.
     */
    #[Test]
    public function aMalformedRevisionIdNeverReachesTheHost(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        AdminSurfaceServiceProvider::registerPageBuilderRoutes($router, $this->host(['ok' => true, 'data' => []]));

        self::assertSame(
            'admin_surface.page_builder.revision',
            $router->match('/admin/_surface/page-builder/pages/42/revisions/5')['_route'],
        );

        foreach (['0', 'abc', '-1', '007a'] as $malformed) {
            $this->expectRouteMiss($router, '/admin/_surface/page-builder/pages/42/revisions/' . $malformed);
        }
    }

    /**
     * End to end through the real host: no actor is a real 401 on all seven.
     */
    #[Test]
    #[DataProvider('allSevenRoutes')]
    public function theRealHostAnswersAnUnresolvableActorWithARealHttp401(
        string $routeName,
        array $routeParams,
        string $method,
        string $body,
    ): void {
        $response = $this->dispatch(
            new GenericPageBuilderSurfaceHost(new PageBuilderSurfaceRegistry()),
            $routeName,
            $routeParams,
            $method,
            $body,
        );

        self::assertSame(401, $response->getStatusCode(), $routeName . ' must refuse an unresolvable actor with 401');
        self::assertSame(401, $this->decode($response)['error']['status']);
    }

    /**
     * End to end through the real host: an unknown surface is a real 404.
     */
    #[Test]
    #[DataProvider('allSevenRoutes')]
    public function theRealHostAnswersAnUnknownSurfaceWithARealHttp404(
        string $routeName,
        array $routeParams,
        string $method,
        string $body,
    ): void {
        $response = $this->dispatch(
            new GenericPageBuilderSurfaceHost(new PageBuilderSurfaceRegistry()),
            $routeName,
            $routeParams,
            $method,
            $body,
            $this->principal(['edit pages']),
        );

        self::assertSame(404, $response->getStatusCode(), $routeName . ' must refuse an unknown surface with 404');
        self::assertSame(404, $this->decode($response)['error']['status']);
    }

    /**
     * End to end through the real host: a permission-less principal is a real 403.
     */
    #[Test]
    #[DataProvider('allSevenRoutes')]
    public function theRealHostAnswersAPermissionLessPrincipalWithARealHttp403(
        string $routeName,
        array $routeParams,
        string $method,
        string $body,
    ): void {
        $response = $this->dispatch(
            $this->realHostWithRegisteredSurface(),
            $routeName,
            $routeParams,
            $method,
            $body,
            $this->principal([]),
        );

        self::assertSame(403, $response->getStatusCode(), $routeName . ' must refuse a denied principal with 403');
        self::assertSame(403, $this->decode($response)['error']['status']);
        self::assertSame('Page builder access denied', $this->decode($response)['error']['title']);
    }

    /**
     * Dispatch a registered route closure through a real `ControllerDispatcher`.
     *
     * @param array<string, string> $routeParams
     */
    private function dispatch(
        PageBuilderSurfaceHostInterface $host,
        string $routeName,
        array $routeParams = [],
        string $method = 'GET',
        string $body = '',
        ?AuthorizationPrincipalInterface $principal = null,
    ): Response {
        $router = new WaaseyaaRouter(new RequestContext('', $method));
        AdminSurfaceServiceProvider::registerPageBuilderRoutes($router, $host);

        $route = $router->getRouteCollection()->get($routeName);
        self::assertNotNull($route, sprintf('Route %s must be registered', $routeName));

        $request = Request::create('/', $method, content: $body);
        $request->attributes->set('_controller', $route->getDefault('_controller'));
        if ($principal !== null) {
            $request->attributes->set('_authorization_principal', $principal);
        }
        foreach ($routeParams as $name => $value) {
            $request->attributes->set($name, $value);
        }

        return new ControllerDispatcher([])->dispatch($request);
    }

    private function expectRouteMiss(WaaseyaaRouter $router, string $path): void
    {
        try {
            $router->match($path);
            self::fail($path . ' must not match any page-builder route');
        } catch (RouteNotFoundException) {
            self::assertTrue(true);
        }
    }

    /**
     * Assert the response body is byte-for-byte what these routes have always
     * emitted for `$expected`.
     *
     * The expectation is written out as the raw `json_encode` Foundation's
     * `JsonApiResponseTrait` produces rather than rebuilt from the response, so
     * it pins the pre-change bytes independently of the implementation. Compared
     * as bytes, not decoded arrays, on purpose: decoding normalises away real
     * distinctions — `403.0` round-trips to `403`, an empty JSON object to an
     * empty PHP array — and those are exactly what a serialisation regression
     * would hide.
     *
     * @param array<string, mixed> $expected
     */
    private function assertBodyIs(array $expected, Response $response, string $message = ''): void
    {
        self::assertSame(
            json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            $response->getContent(),
            $message,
        );
        self::assertSame(
            'application/vnd.api+json',
            $response->headers->get('Content-Type'),
            'The page-builder media type must not change',
        );
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $permissions */
    private function principal(array $permissions): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal(
            accountId: 7,
            authenticated: true,
            roles: ['editor'],
            permissions: $permissions,
            claimsGeneration: 'test',
        );
    }

    /**
     * A host that answers every one of the seven handlers with `$envelope`.
     *
     * @param array<string, mixed> $envelope
     */
    private function host(array $envelope): PageBuilderSurfaceHostInterface
    {
        return new class ($envelope) implements PageBuilderSurfaceHostInterface {
            /** @param array<string, mixed> $envelope */
            public function __construct(private readonly array $envelope) {}

            public function handleDefinitions(PageBuilderSurfaceRequest $request, string $surface): array
            {
                return $this->envelope;
            }

            public function handleDraft(PageBuilderSurfaceRequest $request, string $surface, string $id): array
            {
                return $this->envelope;
            }

            public function handleCommand(PageBuilderSurfaceRequest $request, string $surface, string $id): array
            {
                return $this->envelope;
            }

            public function handlePreview(PageBuilderSurfaceRequest $request, string $surface, string $id): array
            {
                return $this->envelope;
            }

            public function handleHistory(PageBuilderSurfaceRequest $request, string $surface, string $id): array
            {
                return $this->envelope;
            }

            public function handleRevision(PageBuilderSurfaceRequest $request, string $surface, string $id, string $revision): array
            {
                return $this->envelope;
            }

            public function handleRestore(PageBuilderSurfaceRequest $request, string $surface, string $id): array
            {
                return $this->envelope;
            }
        };
    }

    /**
     * The real host over a surface registered as `pages`.
     *
     * The gateways are never reached: `PageBuilderSurface::assertAllowed()`
     * refuses before any of them is consulted, which is the point of the 403
     * case they support.
     */
    private function realHostWithRegisteredSurface(): GenericPageBuilderSurfaceHost
    {
        $definitions = new DefinitionRegistry();
        $codec = new CanonicalLayoutCodec();
        $validator = new LayoutValidator($definitions);

        $surfaces = new PageBuilderSurfaceRegistry();
        $surfaces->register('pages', new PageBuilderSurface(
            'edit pages',
            $definitions,
            new LayoutDraftManager(
                $this->createStub(LayoutDraftGatewayInterface::class),
                $codec,
                $validator,
                new LayoutEditor($codec, $validator, $definitions),
            ),
            $this->createStub(RevisionPreviewGatewayInterface::class),
        ));

        return new GenericPageBuilderSurfaceHost($surfaces);
    }

    private static function commandBody(): string
    {
        return json_encode([
            'expected_entity_revision_id' => 1,
            'expected_document_fingerprint' => str_repeat('a', 64),
            'idempotency_key' => 'operation-1',
            'command' => self::WIRE_COMMAND,
        ], JSON_THROW_ON_ERROR);
    }

    private static function restoreBody(): string
    {
        return json_encode([
            'target_revision_id' => 1,
            'expected_current_revision_id' => 1,
            'idempotency_key' => 'operation-1',
        ], JSON_THROW_ON_ERROR);
    }

    /** Guards the assumption the route matrix is built on. */
    #[Test]
    public function theMatrixCoversEveryRegisteredPageBuilderRoute(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        AdminSurfaceServiceProvider::registerPageBuilderRoutes($router, $this->host(['ok' => true, 'data' => []]));

        $registered = array_keys(iterator_to_array($router->getRouteCollection()->getIterator()));
        $covered = array_column(self::allSevenRoutes(), 0);
        sort($registered);
        sort($covered);

        self::assertSame($registered, $covered, 'Every registered page-builder route must be in the matrix');
        self::assertCount(7, $registered);
        self::assertSame(
            AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_REVISION,
            $router->getRouteCollection()->get('admin_surface.page_builder.revision')?->getPath(),
        );
    }
}
