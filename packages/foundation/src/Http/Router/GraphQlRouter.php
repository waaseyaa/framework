<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

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
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'graphql.endpoint';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);

        // Prefer the middleware-resolved session account over the
        // route-level $account, which is AnonymousUser on allowAll() routes.
        $resolvedAccount = $request->attributes->get('_account');
        $graphqlAccount = ($resolvedAccount instanceof AccountInterface && $resolvedAccount->isAuthenticated())
            ? $resolvedAccount
            : $ctx->account;

        $endpoint = new \Waaseyaa\GraphQL\GraphQlEndpoint(
            entityTypeManager: $this->entityTypeManager,
            accessHandler: $this->accessHandler,
            account: $graphqlAccount,
        );

        if ($this->mutationOverrides !== []) {
            $endpoint = $endpoint->withMutationOverrides($this->mutationOverrides);
        }

        $result = $endpoint->handle(
            $ctx->method,
            $request->getContent(),
            $request->query->all(),
        );

        return $this->jsonApiResponse(200, $result);
    }
}
