<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasGraphqlMutationOverridesInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\GraphQL\Http\Router\GraphQlRouter;
use Waaseyaa\Routing\WaaseyaaRouter;

final class GraphQlServiceProvider extends ServiceProvider implements HasHttpDomainRoutersInterface
{
    public function register(): void {}

    public function httpDomainRouters(HttpKernel $httpKernel): iterable
    {
        $gqlOverrides = [];
        foreach ($httpKernel->getProviders() as $provider) {
            if (!$provider instanceof HasGraphqlMutationOverridesInterface) {
                continue;
            }
            foreach ($provider->graphqlMutationOverrides($httpKernel->getEntityTypeManager()) as $name => $override) {
                $gqlOverrides[$name] = $override;
            }
        }

        return [
            new GraphQlRouter(
                $httpKernel->getEntityTypeManager(),
                $httpKernel->getAccessHandler(),
                $gqlOverrides,
                // The kernel's boot-scoped field-type registry (#2786 B1): the
                // wire-type adapter admits exactly the ids the field registry did.
                $httpKernel->getFieldTypeManager(),
            ),
        ];
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        new GraphQlRouteProvider()->registerRoutes($router);
    }
}
