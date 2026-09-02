<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/**
 * One optional (Composer `suggest`) package a provider's contribution depends on.
 *
 * The sentinel is a class, interface, or enum FQCN that exists only when the
 * package is installed; Composer autoload presence is the install signal, the
 * same signal `class_exists()` gates elsewhere in the kernel. A requirement is
 * evaluated from the provider's class name alone (see
 * {@see RequiresOptionalPackagesInterface}), so package discovery and the
 * console runtime reach the same verdict without instantiating the provider.
 *
 * @api
 */
final readonly class OptionalPackageRequirement
{
    private const string COMPOSER_NAME = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#D';

    /**
     * @param string $package       Composer package name, for example `waaseyaa/ai-agent`.
     * @param string $sentinelClass FQCN that is autoloadable only when the package is installed.
     * @param string $purpose       What the provider contributes when the package is present.
     */
    public function __construct(
        public string $package,
        public string $sentinelClass,
        public string $purpose,
    ) {
        if (preg_match(self::COMPOSER_NAME, $package) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a Composer package name.', $package));
        }
        if ($sentinelClass === '' || str_starts_with($sentinelClass, '\\')) {
            throw new \InvalidArgumentException('An optional package requirement needs an unqualified sentinel FQCN without a leading backslash.');
        }
        if (trim($purpose) === '') {
            throw new \InvalidArgumentException('An optional package requirement must state its purpose.');
        }
    }

    /** True when the sentinel is autoloadable, which means the optional package is installed. */
    public function isSatisfied(): bool
    {
        return class_exists($this->sentinelClass)
            || interface_exists($this->sentinelClass)
            || enum_exists($this->sentinelClass);
    }
}
