<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\Exception\PolicyInstantiationException;
use Waaseyaa\Foundation\Log\LoggerInterface;

final class AccessPolicyRegistry
{
    private readonly PolicyDependencyResolverInterface $resolver;

    public function __construct(
        LoggerInterface $logger,
        ?PolicyDependencyResolverInterface $resolver = null,
    ) {
        // Retained for constructor compatibility; missing policies now throw.
        unset($logger);
        $this->resolver = $resolver ?? new NullPolicyDependencyResolver();
    }

    /**
     * Discover and instantiate access policies from the manifest using a two-phase algorithm.
     *
     * Phase 1: Instantiate all policies whose constructors do NOT require EntityAccessHandler.
     *          Build a preliminary EntityAccessHandler from phase-1 policies.
     *
     * Phase 2: Instantiate deferred policies (those that require EntityAccessHandler),
     *          substituting the preliminary handler where needed. Build the final
     *          EntityAccessHandler from all policies.
     *
     * Any policy whose constructor dependency cannot be resolved throws
     * PolicyInstantiationException immediately — no silent log-and-continue.
     *
     * @throws PolicyInstantiationException When any #[PolicyAttribute] class cannot be instantiated.
     */
    public function discover(PackageManifest $manifest): EntityAccessHandler
    {
        /** @var list<\Waaseyaa\Access\AccessPolicyInterface> $phase1Policies */
        $phase1Policies = [];
        /** @var list<array{class-string, array<string>}> $deferred */
        $deferred = [];

        // Phase 1: instantiate policies that do not require EntityAccessHandler.
        foreach ($manifest->policies as $class => $entityTypes) {
            if (!class_exists($class)) {
                throw new PolicyInstantiationException(sprintf(
                    'Access policy class not found: %s (covering entity types: %s). '
                    . 'Refusing to boot with incomplete access enforcement.',
                    $class,
                    implode(', ', $entityTypes),
                ));
            }

            $ref = new \ReflectionClass($class);
            $constructor = $ref->getConstructor();

            if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
                $phase1Policies[] = $ref->newInstance();
                continue;
            }

            // Check if any parameter requires EntityAccessHandler → defer to phase 2.
            if ($this->requiresEntityAccessHandler($constructor)) {
                $deferred[] = [$class, $entityTypes];
                continue;
            }

            // Resolve all parameters; throws PolicyInstantiationException on failure.
            $args = $this->resolveParameters($class, $constructor, $entityTypes);
            $phase1Policies[] = $ref->newInstanceArgs($args);
        }

        // Build preliminary EntityAccessHandler from phase-1 policies.
        $preliminaryHandler = new EntityAccessHandler($phase1Policies);

        if ($deferred === []) {
            return $preliminaryHandler;
        }

        // Phase 2: instantiate deferred policies using the preliminary handler,
        // then mutate the preliminary handler in-place via addPolicy(). This
        // avoids creating a second EntityAccessHandler instance — phase-2 policies
        // that hold a reference to $preliminaryHandler see the final state.
        if ($this->resolver instanceof KernelPolicyDependencyResolver) {
            $this->resolver->setPreliminaryHandler($preliminaryHandler);
        }

        foreach ($deferred as [$class, $entityTypes]) {
            $ref = new \ReflectionClass($class);
            $constructor = $ref->getConstructor();

            if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
                $preliminaryHandler->addPolicy($ref->newInstance());
                continue;
            }

            $args = $this->resolveParameters($class, $constructor, $entityTypes);
            $preliminaryHandler->addPolicy($ref->newInstanceArgs($args));
        }

        return $preliminaryHandler;
    }

    /**
     * @return list<mixed>
     * @throws PolicyInstantiationException
     */
    private function resolveParameters(string $class, \ReflectionMethod $constructor, mixed $entityTypes): array
    {
        $args = [];
        foreach ($constructor->getParameters() as $param) {
            try {
                $args[] = $this->resolver->resolveParameter($class, $param, $entityTypes);
            } catch (\Throwable $e) {
                if ($e instanceof PolicyInstantiationException) {
                    throw $e;
                }
                throw new PolicyInstantiationException(sprintf(
                    'Failed to resolve constructor parameter "%s" for access policy %s: %s',
                    $param->getName(),
                    $class,
                    $e->getMessage(),
                ), 0, $e);
            }
        }

        return $args;
    }

    /**
     * Check if any constructor parameter is typed as EntityAccessHandler.
     */
    private function requiresEntityAccessHandler(\ReflectionMethod $constructor): bool
    {
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === EntityAccessHandler::class) {
                return true;
            }
        }

        return false;
    }
}
