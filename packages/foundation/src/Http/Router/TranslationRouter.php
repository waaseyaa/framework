<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\TranslationController;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

/**
 * Domain router for translation CRUD sub-endpoints.
 *
 * Handles requests routed to TranslationController (registered by
 * JsonApiRouteProvider::registerTranslationRoutes):
 *
 *   GET    /api/{entity_type}/{id}/translations
 *   GET    /api/{entity_type}/{id}/translations/{langcode}
 *   POST   /api/{entity_type}/{id}/translations/{langcode}
 *   PATCH  /api/{entity_type}/{id}/translations/{langcode}
 *   DELETE /api/{entity_type}/{id}/translations/{langcode}
 */
final class TranslationRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly ?EntityTypeApiExposurePolicy $exposurePolicy = null,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        $controllerString = match (true) {
            is_string($controller) => $controller,
            is_array($controller) && is_string($controller[0] ?? null) => $controller[0],
            default => '',
        };

        return str_contains($controllerString, 'TranslationController');
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();
        $serializer = new ResourceSerializer($this->entityTypeManager, exposurePolicy: $this->exposurePolicy);

        $translationController = new TranslationController(
            $this->entityTypeManager,
            $this->accessHandler,
            $serializer,
        );

        $entityTypeId = $params['_entity_type'] ?? '';
        $id = $params['id'] ?? null;
        $langcode = $params['langcode'] ?? null;

        $document = match (true) {
            $ctx->method === 'GET' && $langcode === null => $translationController->index($request, $entityTypeId, $id),
            $ctx->method === 'GET' && $langcode !== null => $translationController->show($request, $entityTypeId, $id, $langcode),
            $ctx->method === 'POST' && $langcode !== null => $translationController->store($request, $entityTypeId, $id, $langcode, $ctx->parsedBody ?? []),
            $ctx->method === 'PATCH' && $langcode !== null => $translationController->update($request, $entityTypeId, $id, $langcode, $ctx->parsedBody ?? []),
            $ctx->method === 'DELETE' && $langcode !== null => $translationController->destroy($request, $entityTypeId, $id, $langcode),
            default => JsonApiDocument::fromErrors(
                [new JsonApiError('400', 'Bad Request', 'Unhandled method/resource combination for translation endpoint.')],
                statusCode: 400,
            ),
        };

        return $this->jsonApiResponse($document->statusCode, $document->toArray());
    }
}
