<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Kernel;

use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * A typed kernel-service resolver for provider contract tests.
 *
 * @api
 */
final class KernelServicesFixture implements KernelServicesInterface
{
    /** @var \Closure(string): ?object|null */
    private readonly ?\Closure $fallback;

    /**
     * @param array<string, object> $services
     * @param (callable(string): ?object)|null $fallback
     */
    public function __construct(
        private readonly array $services = [],
        ?callable $fallback = null,
    ) {
        $this->fallback = $fallback === null ? null : \Closure::fromCallable($fallback);
    }

    public function get(string $abstract): ?object
    {
        return $this->services[$abstract] ?? ($this->fallback === null ? null : ($this->fallback)($abstract));
    }
}
