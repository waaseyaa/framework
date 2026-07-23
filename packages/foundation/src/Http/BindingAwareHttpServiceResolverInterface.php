<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http;

/**
 * Optional resolver capability for callers that must distinguish an absent
 * binding from a bound service whose factory failed.
 *
 * @internal Kernel/SSR coordination detail; applications bind the public
 *           EntityPageComposerInterface instead.
 */
interface BindingAwareHttpServiceResolverInterface extends HttpServiceResolverInterface
{
    public function hasBinding(string $className): bool;

    /**
     * Resolve a known binding without converting factory failures to null.
     *
     * @throws \Throwable When the bound service cannot be constructed.
     */
    public function resolveBound(string $className): object;
}
