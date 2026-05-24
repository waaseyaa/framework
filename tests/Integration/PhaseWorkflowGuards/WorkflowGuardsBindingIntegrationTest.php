<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseWorkflowGuards;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Controller\WorkflowGuardsController;
use Waaseyaa\Api\Http\Router\WorkflowGuardsApiRouter;
use Waaseyaa\Workflows\AuthoringRoleMatrix;
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * Regression test for the M4A-5 Phase 1 cycle-2 fix: prove that
 * {@see WorkflowServiceProvider::register()} produces a live
 * {@see AuthoringRoleMatrix} binding seeded with the framework's editorial
 * guard data, so the {@see WorkflowGuardsController} returns non-empty rows
 * on a default kernel boot.
 *
 * The cycle-1 implementation defined the matrix class and the controller
 * but never wired the matrix into the container, so `ApiServiceProvider`'s
 * `resolveOptional(AuthoringRoleMatrix::class)` silently returned null and
 * the entire dashboard surface was dead code in production. This test would
 * fail on the cycle-1 head (binding absent → resolve() throws → dashboard
 * empty) and passes once the binding lands in cycle 2.
 *
 * The test does not stand up the full HTTP kernel; instead it exercises the
 * same `ServiceProvider::register()` → `ServiceProvider::resolve()` contract
 * that {@see \Waaseyaa\Api\ApiServiceProvider::routers()} relies on to wire
 * the dashboard surface, then hits the controller + router pair end-to-end
 * with the container-resolved matrix to prove a non-empty response.
 *
 * Spec: kitty-specs/workflow-guards-readonly-01KSDS5W/spec.md (parent #1470,
 * Phase 1 dashboard liveness).
 */
#[CoversNothing]
final class WorkflowGuardsBindingIntegrationTest extends TestCase
{
    #[Test]
    public function workflowServiceProviderBindsAuthoringRoleMatrixWithEditorialGuards(): void
    {
        // Exercise the exact pattern `ApiServiceProvider::routers()` uses:
        // register() the provider, then resolve(AuthoringRoleMatrix::class).
        // If WorkflowServiceProvider::register() omitted the binding, the
        // resolve() call below would throw a `RuntimeException("No binding
        // registered for ...")` — the cycle-1 head's failure mode.
        $provider = new WorkflowServiceProvider();
        $provider->register();

        $matrix = $provider->resolve(AuthoringRoleMatrix::class);

        self::assertInstanceOf(AuthoringRoleMatrix::class, $matrix);

        $editorialId = EditorialWorkflowPreset::create()->id();
        self::assertIsString($editorialId, 'Editorial workflow id must be a string for matrix lookup.');

        // The binding lists the editorial workflow id in its known set.
        self::assertContains(
            $editorialId,
            $matrix->knownWorkflowIds(),
            'WorkflowServiceProvider must register guard rows under the editorial workflow id.',
        );

        // forWorkflow() returns the rows the dashboard renders. The exact
        // shape of `bundle` is intentionally not asserted (it is a
        // framework-default sentinel that downstream apps may override by
        // rebinding); only liveness — at least one row per documented
        // transition — is what cycle 1 missed.
        $rows = $matrix->forWorkflow($editorialId);
        self::assertNotEmpty(
            $rows,
            'forWorkflow() must return non-empty rows so the dashboard is not dead code in production.',
        );

        // Editorial transitions documented in EditorialWorkflowPreset must
        // each be present at least once in the bound matrix.
        $transitionIds = array_values(array_unique(array_column($rows, 'transition')));
        sort($transitionIds);
        self::assertSame(
            ['archive', 'publish', 'restore', 'send_back', 'submit_for_review', 'unpublish'],
            $transitionIds,
            'Bound matrix must expose every transition role-matrix entry the editorial resolver owns.',
        );

        // required_roles must be non-empty for each row — empty role lists
        // would mean the dashboard renders a transition column with no chips.
        foreach ($rows as $row) {
            self::assertNotEmpty(
                $row['required_roles'],
                sprintf('Transition "%s" must surface at least one required role.', $row['transition']),
            );
        }
    }

    #[Test]
    public function workflowGuardsControllerReturnsNonEmptyDataWhenWiredWithBoundMatrix(): void
    {
        // End-to-end proof: the binding produced by the service provider is
        // sufficient for the controller + router pair (the exact wiring
        // `ApiServiceProvider::routers()` uses) to serve a non-empty 200
        // response on `GET /api/workflow-definitions/editorial/guards`. This
        // is the dashboard liveness path the cycle-1 review flagged as dead.
        $provider = new WorkflowServiceProvider();
        $provider->register();

        $matrix = $provider->resolve(AuthoringRoleMatrix::class);
        self::assertInstanceOf(AuthoringRoleMatrix::class, $matrix);

        $controller = new WorkflowGuardsController(
            matrix: $matrix,
            workflowsProvider: static fn(): array => [EditorialWorkflowPreset::create()],
        );
        $router = new WorkflowGuardsApiRouter($controller);

        $editorialId = (string) EditorialWorkflowPreset::create()->id();
        $request = Request::create(
            sprintf('/api/workflow-definitions/%s/guards', $editorialId),
            'GET',
        );
        $request->attributes->set(
            '_controller',
            'Waaseyaa\\Api\\Controller\\WorkflowGuardsController::index',
        );
        $request->attributes->set('workflow_id', $editorialId);

        self::assertTrue($router->supports($request));
        $response = $router->handle($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(
            200,
            $response->getStatusCode(),
            'Container-resolved matrix must produce a 200 response on the editorial workflow id.',
        );

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);
        self::assertNotEmpty(
            $body['data'],
            'Dashboard endpoint must return non-empty data; cycle-1 returned [] because the matrix was unbound.',
        );

        // Every row carries the documented shape.
        foreach ($body['data'] as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('bundle', $row);
            self::assertArrayHasKey('transition', $row);
            self::assertArrayHasKey('required_roles', $row);
            self::assertIsString($row['bundle']);
            self::assertIsString($row['transition']);
            self::assertIsArray($row['required_roles']);
        }
    }

    #[Test]
    public function singletonBindingReturnsSameInstanceOnRepeatedResolve(): void
    {
        // The binding is registered with `singleton()`; consumers
        // (ApiServiceProvider, future dashboards) must see the same instance
        // to avoid surprising per-request re-derivation cost. This locks the
        // shared-binding contract.
        $provider = new WorkflowServiceProvider();
        $provider->register();

        $first = $provider->resolve(AuthoringRoleMatrix::class);
        $second = $provider->resolve(AuthoringRoleMatrix::class);

        self::assertSame($first, $second, 'AuthoringRoleMatrix must be bound as a singleton.');
    }
}
