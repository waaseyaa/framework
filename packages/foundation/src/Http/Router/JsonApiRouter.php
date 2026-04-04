<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class JsonApiRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly DatabaseInterface $database,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');

        return str_contains($controller, 'JsonApiController');
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();
        $serializer = new ResourceSerializer($this->entityTypeManager);

        $jsonApiController = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $ctx->account,
        );

        $entityTypeId = $params['_entity_type'] ?? '';
        $id = $params['id'] ?? null;

        $document = match (true) {
            $ctx->method === 'GET' && $id === null => $jsonApiController->index($entityTypeId, $ctx->query),
            $ctx->method === 'GET' && $id !== null => $jsonApiController->show($entityTypeId, $id),
            $ctx->method === 'POST' => $jsonApiController->store($entityTypeId, $ctx->parsedBody ?? []),
            $ctx->method === 'PATCH' && $id !== null => $jsonApiController->update($entityTypeId, $id, $ctx->parsedBody ?? []),
            $ctx->method === 'DELETE' && $id !== null => $jsonApiController->destroy($entityTypeId, $id),
            default => JsonApiDocument::fromErrors(
                [new JsonApiError('400', 'Bad Request', 'Unhandled method/resource combination.')],
                statusCode: 400,
            ),
        };

        return $this->jsonApiResponse($document->statusCode, $document->toArray());
    }
}
