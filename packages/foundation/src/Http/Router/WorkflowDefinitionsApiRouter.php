<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Workflow\WorkflowDefinitionsController;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

/**
 * Dispatches workflow-definition read endpoints for the admin SPA.
 */
final class WorkflowDefinitionsApiRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

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
        $apiController = new WorkflowDefinitionsController();

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
