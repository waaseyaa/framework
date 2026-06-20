<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Catalogue;

use Psr\Container\ContainerInterface;
use Waaseyaa\AI\Tools\ToolDependencyUnavailableException;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Resolves `#[AsAgentTool]` classes for {@see AttributeToolRegistry}.
 *
 * Tool classes are not container-bound, so resolution proceeds:
 *   1. the kernel-services bus ({@see KernelServicesInterface::get()} — core
 *      services PLUS any service bound by a provider, via its provider walk);
 *   2. the owning provider's own bindings;
 *   3. reflection autowiring — instantiate the class, resolving each
 *      constructor dependency through (1) (recursively for concrete classes),
 *      with `nullable -> null` and `has-default -> default` fallbacks (the same
 *      parameter rules the kernel uses for access-policy autowiring).
 *
 * Unresolvable dependencies throw; {@see AttributeToolRegistry::hydrate()}
 * catches per-tool and skips, so one un-instantiable tool never breaks the rest.
 *
 * @api
 */
final class AutowiringToolContainer implements ContainerInterface
{
    public function __construct(
        private readonly ?KernelServicesInterface $kernelServices,
        private readonly ServiceProvider $provider,
    ) {}

    public function get(string $id): object
    {
        $service = $this->kernelServices?->get($id);
        if (\is_object($service)) {
            return $service;
        }

        $bindings = $this->provider->getBindings();
        if (isset($bindings[$id])) {
            return $this->provider->resolve($id);
        }

        return $this->autowire($id);
    }

    public function has(string $id): bool
    {
        try {
            $this->get($id);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param class-string|string $class
     */
    private function autowire(string $class): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('Cannot autowire unknown class "%s".', $class));
        }

        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException(sprintf('Class "%s" is not instantiable.', $class));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $args[] = $this->resolveParameter($class, $parameter);
        }

        return $reflection->newInstanceArgs($args);
    }

    private function resolveParameter(string $owner, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            try {
                $service = $this->kernelServices?->get($typeName);
                if (\is_object($service)) {
                    return $service;
                }
            } catch (\Throwable $e) {
                // A bound service whose OWN factory could not be satisfied in
                // this kernel (e.g. a routing-introspection service needing a
                // RouteCollection that isn't bound here). If this parameter has
                // no fallback, the owning tool is simply unavailable in this
                // configuration — a quiet, expected skip, not a hard error.
                if (!$parameter->isDefaultValueAvailable() && !$parameter->allowsNull()) {
                    throw ToolDependencyUnavailableException::forDependency($owner, $typeName, $e);
                }
                // else: fall through to default/null below.
            }

            // Concrete class dependency not on the bus: recurse.
            if (class_exists($typeName)) {
                try {
                    return $this->autowire($typeName);
                } catch (\Throwable) {
                    // Fall through to nullable/default handling below.
                }
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($parameter->allowsNull()) {
            return null;
        }

        // Unresolvable required dependency: the tool cannot be built in this
        // kernel. Typed so the registry skips it quietly (it is an optional
        // tool whose deps are absent — e.g. VectorSearchTool's embedding-provider
        // closures with no binding), rather than logging it as a failure.
        $typeLabel = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
        throw ToolDependencyUnavailableException::forDependency(
            $owner,
            sprintf('$%s (%s)', $parameter->getName(), $typeLabel),
        );
    }
}
