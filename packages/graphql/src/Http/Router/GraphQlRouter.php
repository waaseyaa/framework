<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Http\Router\WaaseyaaContext;
use Waaseyaa\GraphQL\GraphQlEndpoint;

final class GraphQlRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param array<string, array{args?: array<string, mixed>, resolve?: callable}> $mutationOverrides
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly array $mutationOverrides = [],
        private readonly ?FieldTypeManagerInterface $fieldTypes = null,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'graphql.endpoint';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);

        $endpoint = new GraphQlEndpoint(
            entityTypeManager: $this->entityTypeManager,
            accessHandler: $this->accessHandler,
            account: $ctx->principal,
            fieldTypes: $this->fieldTypes,
        );

        if ($this->mutationOverrides !== []) {
            $endpoint = $endpoint->withMutationOverrides($this->mutationOverrides);
        }

        $result = $endpoint->handle(
            $ctx->method,
            $request->getContent(),
            $request->query->all(),
        );

        return $this->jsonApiResponse($result['statusCode'], $result);
    }
}
