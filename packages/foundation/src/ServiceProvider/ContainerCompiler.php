<?php

declare(strict_types=1);

namespace Aurora\Foundation\ServiceProvider;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ContainerCompiler
{
    /**
     * @param ServiceProviderInterface[] $providers
     */
    public function compile(array $providers, ContainerBuilder $container): void
    {
        // Phase 1: register all bindings
        foreach ($providers as $provider) {
            $provider->register();

            foreach ($provider->getBindings() as $abstract => $binding) {
                $concrete = $binding['concrete'];
                $definition = new Definition(is_string($concrete) ? $concrete : \stdClass::class);
                $definition->setShared($binding['shared']);
                $definition->setPublic(true);

                if (is_callable($concrete) && !is_string($concrete)) {
                    $definition->setFactory($concrete);
                }

                $container->setDefinition($abstract, $definition);
            }

            foreach ($provider->getTags() as $tag => $services) {
                foreach ($services as $serviceId) {
                    if ($container->hasDefinition($serviceId)) {
                        $container->getDefinition($serviceId)->addTag($tag);
                    }
                }
            }
        }

        // Phase 2: boot all providers (all bindings available)
        foreach ($providers as $provider) {
            $provider->boot();
        }
    }
}
