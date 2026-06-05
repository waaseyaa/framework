<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\OpenApi\OpenApiGenerator;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class SchemaRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly ?FieldDefinitionRegistryInterface $fieldDefinitionRegistry = null,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');
        return $controller === 'openapi' || str_contains($controller, 'SchemaController');
    }

    public function handle(Request $request): Response
    {
        $controller = $request->attributes->get('_controller', '');

        if ($controller === 'openapi') {
            $openApi = new OpenApiGenerator($this->entityTypeManager);
            return $this->jsonApiResponse(200, $openApi->generate());
        }

        $ctx = WaaseyaaContext::fromRequest($request);
        $schemaPresenter = new SchemaPresenter($this->fieldDefinitionRegistry);
        $schemaController = new SchemaController($this->entityTypeManager, $schemaPresenter, $this->accessHandler, $ctx->account);
        $bundle = $request->query->get('bundle');
        $document = $schemaController->show(
            $request->attributes->get('entity_type'),
            is_string($bundle) && $bundle !== '' ? $bundle : null,
        );
        return $this->jsonApiResponse($document->statusCode, $document->toArray());
    }
}
