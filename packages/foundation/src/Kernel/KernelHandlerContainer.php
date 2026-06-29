<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * PSR-11 container used by ConsoleApplicationFactory and handler-backed
 * Symfony commands to resolve class-based handlers at dispatch time.
 *
 * Resolution order:
 *   1. Explicit kernel-owned bindings ($kernelBindings map).
 *   2. Each provider's resolve() — covers all framework services and
 *      explicitly bound abstracts (EntityTypeManager, DatabaseInterface, …).
 *   3. Reflection-based auto-wiring — instantiates concrete handler classes
 *      whose constructor parameters are resolvable from the same container.
 *
 * Must be obtained after bootForCli() / boot() completes.
 */
final class KernelHandlerContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $cache = [];

    /**
     * @param list<ServiceProvider>                                               $providers
     * @param array<string, \Closure(ContainerInterface): object> $kernelBindings
     */
    public function __construct(
        private readonly array $providers,
        private readonly array $kernelBindings,
    ) {}

    public function get(string $id): object
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        // 1. Explicit kernel bindings (BootDiagnosticReport, HealthCheckerInterface, …).
        if (isset($this->kernelBindings[$id])) {
            $instance = ($this->kernelBindings[$id])($this);
            $this->cache[$id] = $instance;

            return $instance;
        }

        // 2. Provider bindings (EntityTypeManager, DatabaseInterface, …).
        foreach ($this->providers as $provider) {
            try {
                $instance = $provider->resolve($id);
                $this->cache[$id] = $instance;

                return $instance;
            } catch (\RuntimeException $e) {
                // Only a genuinely *unbound* id falls through to the next
                // provider / reflection auto-wiring. resolve() signals that
                // case with the canonical "No binding registered for …"
                // message (ServiceProvider::resolve()). Any other failure is
                // a real construction error (e.g. a factory dependency that
                // could not be built) — re-throw it so the true cause is not
                // masked as a misleading "No binding" NotFoundException.
                if (!str_starts_with($e->getMessage(), 'No binding registered for ')) {
                    throw $e;
                }
                // try next
            }
        }

        // 3. Reflection-based auto-wiring for concrete handler classes.
        if (!class_exists($id)) {
            throw new class ($id) extends \RuntimeException implements NotFoundExceptionInterface {
                public function __construct(string $id)
                {
                    parent::__construct(sprintf('No binding for "%s" in KernelHandlerContainer.', $id));
                }
            };
        }

        $ref = new \ReflectionClass($id);
        $ctor = $ref->getConstructor();

        if ($ctor === null || $ctor->getParameters() === []) {
            $instance = new $id();
            $this->cache[$id] = $instance;

            return $instance;
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
            } elseif ($param->isOptional()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new class ($id, $param->getName()) extends \RuntimeException implements NotFoundExceptionInterface {
                    public function __construct(string $id, string $param)
                    {
                        parent::__construct(sprintf('Cannot auto-wire "%s": unresolvable parameter "$%s".', $id, $param));
                    }
                };
            }
        }

        $instance = $ref->newInstanceArgs($args);
        $this->cache[$id] = $instance;

        return $instance;
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
}
