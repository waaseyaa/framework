<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\FieldAutoSaveController;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/** Adapts matched field-autosave attributes to the controller's scalar contract. */
final readonly class FieldAutoSaveApiRouter implements DomainRouterInterface
{
    public function __construct(private FieldAutoSaveController $controller) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller') === FieldAutoSaveController::class . '::update';
    }

    public function handle(Request $request): Response
    {
        return $this->update($request);
    }

    public function update(Request $request, mixed $id = null, mixed $key = null): Response
    {
        $type = $request->attributes->get('_entity_type');
        $matchedId = $request->attributes->get('id');
        $matchedKey = $request->attributes->get('key');
        return $this->controller->update(
            $request,
            is_scalar($type) ? (string) $type : '',
            is_scalar($matchedId) ? (string) $matchedId : '',
            is_scalar($matchedKey) ? (string) $matchedKey : '',
        );
    }
}
