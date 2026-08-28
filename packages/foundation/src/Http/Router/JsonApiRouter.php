<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Api\Http\EntityMutationPrecondition;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
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
        ?DatabaseInterface $database = null,
        private readonly ?EntityTypeApiExposurePolicy $exposurePolicy = null,
        private readonly ?InternalFieldVisibilityPolicy $internalFieldVisibility = null,
    ) {
        // Retained for positional compatibility with existing construction
        // sites; JSON:API storage is resolved through EntityTypeManager.
        unset($database);
    }

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        // Symfony routes expose _controller as either a "Class::method" string
        // or a [Class, method] callable array. Normalize to a searchable string.
        $controllerString = match (true) {
            is_string($controller) => $controller,
            is_array($controller) && is_string($controller[0] ?? null) => $controller[0],
            default => '',
        };

        return str_contains($controllerString, 'JsonApiController');
    }

    public function handle(Request $request): Response
    {
        $expectation = null;
        if (in_array($request->getMethod(), ['PATCH', 'DELETE'], true)) {
            $expectation = EntityMutationPrecondition::fromRequest($request);
            if ($expectation instanceof JsonApiDocument) {
                return $this->jsonApiResponse($expectation->statusCode, $expectation->toArray());
            }
        }

        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();
        $serializer = new ResourceSerializer($this->entityTypeManager, exposurePolicy: $this->exposurePolicy, internalFieldVisibility: $this->internalFieldVisibility);

        $jsonApiController = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $ctx->principal,
            exposurePolicy: $this->exposurePolicy,
            internalFieldVisibility: $this->internalFieldVisibility,
        );

        $entityTypeId = $params['_entity_type'] ?? '';
        $id = $params['id'] ?? null;

        $document = match (true) {
            $ctx->method === 'GET' && $id === null => $jsonApiController->index($entityTypeId, $ctx->query),
            // CW-v1 option-1 (#1920 PR-3): $ctx->query now reaches show() too
            // — needed for the `?workingCopy=1` toggle (design §4). This also
            // fixes a pre-existing gap where sparse fieldsets (`fields[type]`)
            // silently never reached a single-resource GET over HTTP.
            $ctx->method === 'GET' && $id !== null => $jsonApiController->show($entityTypeId, $id, $ctx->query),
            // #2553: $ctx->query reaches the WRITE path too, for the
            // `?representation=` toggle. It selects the projection of the
            // response echo only; it never influences what is written.
            $ctx->method === 'POST' => $jsonApiController->store($entityTypeId, $ctx->parsedBody ?? [], $ctx->query),
            $ctx->method === 'PATCH' && $id !== null => $jsonApiController->update($entityTypeId, $id, $ctx->parsedBody ?? [], $expectation, $ctx->query),
            $ctx->method === 'DELETE' && $id !== null => $jsonApiController->destroy($entityTypeId, $id, $expectation),
            default => JsonApiDocument::fromErrors(
                [new JsonApiError('400', 'Bad Request', 'Unhandled method/resource combination.')],
                statusCode: 400,
            ),
        };

        return $this->jsonApiResponse($document->statusCode, $document->toArray(), $document->headers);
    }
}
