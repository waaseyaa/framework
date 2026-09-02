<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/**
 * Evaluates a provider's {@see RequiresOptionalPackagesInterface} declarations.
 *
 * Accepts a class name or an instance so package discovery (class names only)
 * and the console runtime (instances) share one verdict. A provider that does
 * not implement the interface has no optional requirements and is satisfied.
 *
 * @api
 */
final class OptionalPackageGate
{
    /**
     * @param class-string|object $provider
     * @return list<OptionalPackageRequirement> every declared requirement whose package is absent
     */
    public static function unsatisfied(string|object $provider): array
    {
        $class = \is_object($provider) ? $provider::class : $provider;
        if (!is_a($class, RequiresOptionalPackagesInterface::class, true)) {
            return [];
        }

        $missing = [];
        foreach ($class::optionalPackageRequirements() as $requirement) {
            if (!$requirement->isSatisfied()) {
                $missing[] = $requirement;
            }
        }

        return $missing;
    }

    /** @param class-string|object $provider */
    public static function satisfied(string|object $provider): bool
    {
        return self::unsatisfied($provider) === [];
    }
}
