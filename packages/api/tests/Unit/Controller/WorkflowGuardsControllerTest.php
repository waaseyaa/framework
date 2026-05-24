<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\WorkflowGuardsController;
use Waaseyaa\Workflows\AuthoringRoleMatrix;
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\Workflow;

#[CoversClass(WorkflowGuardsController::class)]
final class WorkflowGuardsControllerTest extends TestCase
{
    #[Test]
    public function returns_data_envelope_for_known_workflow(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article', 'page'],
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

        $result = $controller->index('editorial');

        self::assertArrayHasKey('data', $result);
        self::assertArrayNotHasKey('errors', $result);
        $rows = $result['data'];
        self::assertCount(4, $rows);
        self::assertSame(
            ['bundle' => 'article', 'transition' => 'archive', 'required_roles' => ['administrator']],
            $rows[0],
        );
        self::assertSame(
            ['bundle' => 'article', 'transition' => 'publish', 'required_roles' => ['editor', 'administrator']],
            $rows[1],
        );
        self::assertSame(
            ['bundle' => 'page', 'transition' => 'archive', 'required_roles' => ['administrator']],
            $rows[2],
        );
        self::assertSame(
            ['bundle' => 'page', 'transition' => 'publish', 'required_roles' => ['editor', 'administrator']],
            $rows[3],
        );
    }

    #[Test]
    public function returns_404_envelope_when_workflow_is_not_registered(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: [
                'editorial' => ['publish' => ['editor']],
            ],
        );

        $controller = new WorkflowGuardsController(
            matrix: $matrix,
            // Registry exposes no workflows — anything looks unregistered.
            workflowsProvider: static fn(): array => [],
        );

        $result = $controller->index('editorial');

        self::assertArrayHasKey('errors', $result);
        self::assertArrayNotHasKey('data', $result);
        self::assertSame(404, $result['status']);
        self::assertSame('404', $result['errors'][0]['status']);
        self::assertSame('Not Found', $result['errors'][0]['title']);
        self::assertStringContainsString('editorial', $result['errors'][0]['detail']);
    }

    #[Test]
    public function returns_404_when_workflow_id_is_unknown_to_registry(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: [
                'editorial' => ['publish' => ['editor']],
            ],
        );

        $controller = new WorkflowGuardsController(
            matrix: $matrix,
            workflowsProvider: static fn(): array => [EditorialWorkflowPreset::create()],
        );

        $result = $controller->index('mystery_workflow');

        self::assertArrayHasKey('errors', $result);
        self::assertSame(404, $result['status']);
        self::assertStringContainsString('mystery_workflow', $result['errors'][0]['detail']);
    }

    #[Test]
    public function returns_empty_data_when_workflow_is_registered_but_has_no_guard_entries(): void
    {
        // A workflow can be registered in the registry but absent from the
        // matrix (no guards defined yet). The controller should still
        // return a 200-shaped envelope rather than 404 — the URL is valid.
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: [],
        );

        $controller = new WorkflowGuardsController(
            matrix: $matrix,
            workflowsProvider: static fn(): array => [EditorialWorkflowPreset::create()],
        );

        $result = $controller->index('editorial');

        self::assertArrayHasKey('data', $result);
        self::assertSame([], $result['data']);
    }

    #[Test]
    public function default_workflows_provider_falls_back_to_editorial_preset(): void
    {
        // Smoke-check the default: ensures the controller can be constructed
        // without an explicit registry and resolves the bundled editorial
        // workflow id. Mirrors the M4A-1 controller's default behavior.
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: ['editorial' => ['publish' => ['editor']]],
        );

        $controller = new WorkflowGuardsController(matrix: $matrix);
        $result = $controller->index('editorial');

        self::assertArrayHasKey('data', $result);
        self::assertSame(
            [['bundle' => 'article', 'transition' => 'publish', 'required_roles' => ['editor']]],
            $result['data'],
        );

        // And a workflow id outside the preset must still 404.
        $notFound = $controller->index('not_editorial');
        self::assertArrayHasKey('errors', $notFound);
        self::assertSame(404, $notFound['status']);
    }

    #[Test]
    public function workflow_in_registry_uses_workflow_id_method(): void
    {
        // Defensive smoke test — confirms the registry-iteration uses
        // Workflow::id() and not e.g. label() for the comparison.
        $alpha = new Workflow(['id' => 'alpha', 'label' => 'Alpha']);
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: ['alpha' => ['ship' => ['editor']]],
        );

        $controller = new WorkflowGuardsController(
            matrix: $matrix,
            workflowsProvider: static fn(): array => [$alpha],
        );

        $result = $controller->index('alpha');

        self::assertArrayHasKey('data', $result);
        self::assertSame(
            [['bundle' => 'article', 'transition' => 'ship', 'required_roles' => ['editor']]],
            $result['data'],
        );
    }
}
