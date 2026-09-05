<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Workflow\WorkflowDefinitionsController;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Workflows\Read\ActiveWorkflows;

/**
 * Dispatches workflow-definition read endpoints for the admin SPA.
 *
 * #2835: `$activeWorkflows` is optional so a `core`-only install (no
 * `waaseyaa/workflows` package at all) still boots and serves a well-formed
 * empty result — {@see HttpKernel} resolves the service only when the
 * workflows package is present and wired.
 */
final class WorkflowDefinitionsApiRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(private readonly ?ActiveWorkflows $activeWorkflows = null) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');

        return is_string($controller) && str_contains($controller, 'WorkflowDefinitionsController::');
    }

    public function handle(Request $request): Response
    {
        $controllerRef = $request->attributes->get('_controller', '');
        if (!is_string($controllerRef) || !str_contains($controllerRef, '::')) {
            return $this->jsonApiResponse(500, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Invalid workflow definitions controller reference.']],
            ]);
        }

        [, $action] = explode('::', $controllerRef, 2);

        // --- WorkflowDefinitionsController actions ---
        $activeWorkflows = $this->activeWorkflows;
        $apiController = new WorkflowDefinitionsController(
            $activeWorkflows instanceof ActiveWorkflows
                ? static fn(): array => $activeWorkflows->all()
                : null,
        );

        $payload = match ($action) {
            'list' => $apiController->list(),
            default => null,
        };

        if ($payload === null) {
            return $this->jsonApiResponse(404, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Unknown workflow definitions action: %s', $action)]],
            ]);
        }

        return $this->jsonApiResponse(200, $payload);
    }
}
