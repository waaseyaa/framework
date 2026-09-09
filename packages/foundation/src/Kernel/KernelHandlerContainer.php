<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Waaseyaa\Foundation\ServiceProvider\CircularServiceResolutionException;
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
     * Ids currently being resolved on this container, in the order their
     * resolution started. Guards the recursive paths below — a kernel
     * binding closure that calls back into $container->get(...), and the
     * reflection auto-wiring branch's `$this->get($type->getName())` for
     * constructor parameters — against a circular dependency that would
     * otherwise re-enter forever. Cleared via try/finally around every
     * resolution attempt, success or failure, so a failure never leaves
     * stale in-flight state behind.
     *
     * Keys are prefixed before insertion so PHP cannot coerce a numeric-string
     * id such as `"0"` to an integer array key.
     *
     * @var array<string, string> prefixed lookup key => original id
     */
    private array $resolving = [];

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

        // Circular dependency: $id is already being resolved further up this
        // same get() call stack. Report the ordered in-flight chain from
        // where $id first started to this repeat, rather than recursing to
        // stack/memory exhaustion.
        $resolutionKey = 'id:' . $id;
        if (isset($this->resolving[$resolutionKey])) {
            $chain = array_values($this->resolving);
            $start = array_search($id, $chain, true);
            $cycle = [...array_slice($chain, $start === false ? 0 : $start), $id];

            throw new CircularServiceResolutionException($cycle);
        }

        $this->resolving[$resolutionKey] = $id;
        try {
            return $this->resolveUncached($id);
        } finally {
            unset($this->resolving[$resolutionKey]);
        }
    }

    private function resolveUncached(string $id): object
    {
        // 1. Explicit kernel bindings (BootDiagnosticReport, HealthCheckerInterface, …).
        if (isset($this->kernelBindings[$id])) {
            $instance = ($this->kernelBindings[$id])($this);
            $this->cache[$id] = $instance;

            return $instance;
        }

        // 2. Provider bindings (EntityTypeManager, DatabaseInterface, …).
        //
        // A provider factory for $id that itself fails to resolve one of ITS
        // dependencies surfaces as "No binding registered for <dependency>",
        // which is indistinguishable by prefix from "$id is unbound here".
        // Falling through is kept (a sibling may still bind $id), but the
        // first such failure is remembered so that, if auto-wiring then
        // fails too, the error names the dependency that actually broke
        // instead of the constructor parameter reflection tripped over
        // (#2820: "unresolvable parameter $projectRoot" hid the real
        // "No binding registered for HealthCheckerInterface").
        $dependencyFailure = null;
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
                if ($e->getMessage() !== sprintf('No binding registered for %s.', $id)) {
                    $dependencyFailure ??= $e;
                }
                // try next
            }
        }

        // 3. Reflection-based auto-wiring for concrete handler classes.
        if (!class_exists($id)) {
            throw self::notFound(
                sprintf('No binding for "%s" in KernelHandlerContainer.', $id),
                $dependencyFailure,
            );
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
                throw self::notFound(
                    sprintf('Cannot auto-wire "%s": unresolvable parameter "$%s".', $id, $param->getName()),
                    $dependencyFailure,
                );
            }
        }

        $instance = $ref->newInstanceArgs($args);
        $this->cache[$id] = $instance;

        return $instance;
    }

    /**
     * Build the container's NotFound exception. When a provider DID bind the
     * id but its factory failed on a dependency, that failure is chained as
     * the previous exception and named in the message, so the operator sees
     * the binding that broke rather than only the auto-wiring that could not
     * stand in for it.
     */
    private static function notFound(string $message, ?\RuntimeException $dependencyFailure): NotFoundExceptionInterface&\RuntimeException
    {
        if ($dependencyFailure !== null) {
            $message .= sprintf(
                ' A provider binding exists but its factory failed first: %s',
                $dependencyFailure->getMessage(),
            );
        }

        return new class ($message, $dependencyFailure) extends \RuntimeException implements NotFoundExceptionInterface {
            public function __construct(string $message, ?\Throwable $previous)
            {
                parent::__construct($message, 0, $previous);
            }
        };
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
