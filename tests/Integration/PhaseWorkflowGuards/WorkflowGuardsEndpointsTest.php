<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseWorkflowGuards;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\WorkflowGuardsController;
use Waaseyaa\Api\Http\Router\WorkflowGuardsApiRouter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Workflows\AuthoringRoleMatrix;
use Waaseyaa\Workflows\EditorialWorkflowPreset;

/**
 * End-to-end wiring for the M4A-5 Phase 1 read-only workflow-guards endpoint.
 *
 * Asserts:
 *   1. `BuiltinRouteRegistrar` registers the canonical path and `_role: admin`
 *      option (FR-002, NFR-001).
 *   2. `AccessChecker` accepts an admin account and rejects a non-admin
 *      account on the route (FR-004 — route-option enforcement, not
 *      controller-side).
 *   3. The controller + router pair against a seeded `AuthoringRoleMatrix`
 *      and the editorial workflow registry produces:
 *      - 200 + documented payload shape on a known workflow (FR-001, FR-002),
 *      - 404 envelope on an unknown workflow (FR-003).
 *
 * Spec: kitty-specs/workflow-guards-readonly-01KSDS5W/spec.md (parent #1470).
 */
#[CoversNothing]
final class WorkflowGuardsEndpointsTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));

        // WP5: routes are now registered by ApiServiceProvider::routes().
        $registrar = new BuiltinRouteRegistrar($this->entityTypeManager, [new ApiServiceProvider()]);
        $registrar->register($this->router);
    }

    #[Test]
    public function guardsIndexRouteIsRegistered(): void
    {
        $routes = $this->router->getRouteCollection();
        $route = $routes->get('api.workflow.guards.index');

        self::assertNotNull($route, 'GET /api/workflow-definitions/{workflow_id}/guards must be registered');
        self::assertSame('/api/workflow-definitions/{workflow_id}/guards', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
        self::assertSame(
            'Waaseyaa\\Api\\Controller\\WorkflowGuardsController::index',
            $route->getDefault('_controller'),
        );
    }

    #[Test]
    public function guardsIndexRouteRequiresAdminRole(): void
    {
        $route = $this->router->getRouteCollection()->get('api.workflow.guards.index');
        self::assertNotNull($route);
        self::assertSame('admin', $route->getOption('_role'));
    }

    #[Test]
    public function accessCheckerAllowsAdminAndForbidsNonAdmin(): void
    {
        $route = $this->router->getRouteCollection()->get('api.workflow.guards.index');
        self::assertNotNull($route);

        $checker = new AccessChecker();
        $admin = self::account(['admin']);
        $editor = self::account(['editor']);

        self::assertTrue($checker->check($route, $admin)->isAllowed(), 'admin must be allowed');
        self::assertTrue($checker->check($route, $editor)->isForbidden(), 'non-admin must be forbidden');
    }

    #[Test]
    public function indexEndpointReturnsSeededGuardsForEditorialWorkflow(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: [
                'editorial' => [
                    'publish' => ['editor', 'administrator'],
                    'archive' => ['administrator'],
                ],
            ],
        );
        $controller = new WorkflowGuardsController(
            matrix: $matrix,
            workflowsProvider: static fn(): array => [EditorialWorkflowPreset::create()],
        );
        $router = new WorkflowGuardsApiRouter($controller);

        $request = Request::create('/api/workflow-definitions/editorial/guards', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\WorkflowGuardsController::index');
        $request->attributes->set('workflow_id', 'editorial');

        self::assertTrue($router->supports($request));
        $response = $router->handle($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/vnd.api+json',
            $response->headers->get('Content-Type'),
        );

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('data', $body);
        self::assertCount(2, $body['data']);
        self::assertSame(
            ['bundle' => 'article', 'transition' => 'archive', 'required_roles' => ['administrator']],
            $body['data'][0],
        );
        self::assertSame(
            ['bundle' => 'article', 'transition' => 'publish', 'required_roles' => ['editor', 'administrator']],
            $body['data'][1],
        );
    }

    #[Test]
    public function indexEndpointReturns404ForUnknownWorkflow(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: [],
        );
        $controller = new WorkflowGuardsController(
            matrix: $matrix,
            // Registry exposes no workflows.
            workflowsProvider: static fn(): array => [],
        );
        $router = new WorkflowGuardsApiRouter($controller);

        $request = Request::create('/api/workflow-definitions/mystery/guards', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\WorkflowGuardsController::index');
        $request->attributes->set('workflow_id', 'mystery');

        $response = $router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('errors', $body);
        self::assertSame('404', $body['errors'][0]['status']);
        self::assertSame('Not Found', $body['errors'][0]['title']);
        self::assertStringContainsString('mystery', $body['errors'][0]['detail']);
        // JSON:API envelope is preserved on errors.
        self::assertSame(['version' => '1.1'], $body['jsonapi']);
    }

    #[Test]
    public function routerRejectsUnknownActionWith404(): void
    {
        // Defensive coverage: if a future route mistakenly points at this
        // controller under an unknown action name, the router must surface a
        // JSON:API 404 rather than crashing.
        $matrix = new AuthoringRoleMatrix(bundles: [], roles: []);
        $controller = new WorkflowGuardsController(matrix: $matrix);
        $router = new WorkflowGuardsApiRouter($controller);

        $request = Request::create('/api/workflow-definitions/editorial/guards', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\WorkflowGuardsController::update');

        $response = $router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('update', $body['errors'][0]['detail']);
    }

    /**
     * @param list<string> $roles
     */
    private static function account(array $roles): AccountInterface
    {
        return new class ($roles) implements AccountInterface {
            /**
             * @param list<string> $roles
             */
            public function __construct(private readonly array $roles) {}

            public function id(): int|string
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
