<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/**
 * @api
 *
 * Builds a {@see BackendRegistrar} from a provider classmap.
 *
 * ## Discovery seam
 *
 * The factory accepts an ordered list of provider FQCNs (typically sourced
 * from `PackageManifest` or `installed.json`) and an optional instantiator
 * closure. It iterates the list, instantiates each class that implements
 * {@see HasFieldStorageBackendsInterface}, and separates framework providers
 * from third-party providers by checking for
 * {@see IsFrameworkBackendProviderInterface} via `instanceof`.
 *
 * ## Framework-provider designation
 *
 * A provider is considered "framework-owned" when the instantiated object
 * implements {@see IsFrameworkBackendProviderInterface}. This allows it to
 * register backends under reserved ids. Third-party providers that implement
 * only {@see HasFieldStorageBackendsInterface} are treated as external and
 * cannot claim reserved ids.
 *
 * ## Instantiator seam
 *
 * Pass a `$instantiator` closure to control how provider classes are
 * constructed (e.g. to inject a DI container). Defaults to `new $fqcn()`.
 *
 * @internal WP01 public surface is the factory itself; the returned BackendRegistrar is the @api object.
 */
final class BackendRegistrarFactory
{
    /** @internal String constant avoids an upward layer import into foundation (L0). */
    private const CAPABILITY_INTERFACE = HasFieldStorageBackendsInterface::class;

    /**
     * @param string[] $providerFqcns Ordered provider FQCNs (installed.json order).
     * @param \Closure(string): object|null $instantiator Optional factory for provider instances.
     *        Signature: fn(string $fqcn): object. Defaults to new $fqcn().
     */
    public function __construct(
        private readonly array $providerFqcns,
        private readonly ?\Closure $instantiator = null,
        private readonly ?StrictFieldStorageGatewayAuditInterface $gatewayAudit = null,
    ) {}

    /**
     * Instantiate all providers, classify framework vs. third-party, and
     * return a fully configured (but not yet built) BackendRegistrar.
     *
     * Call BackendRegistrar::build() on the returned instance to populate the index.
     */
    public function create(): BackendRegistrar
    {
        $allFqcns = [];
        $frameworkFqcns = [];

        foreach ($this->providerFqcns as $fqcn) {
            if (!class_exists($fqcn)) {
                continue;
            }

            $implements = class_implements($fqcn);
            if (!is_array($implements)
                || (!in_array(self::CAPABILITY_INTERFACE, $implements, true)
                    && !in_array(HasFieldStorageBackendsV2Interface::class, $implements, true))
            ) {
                continue;
            }

            $instantiator = $this->instantiator ?? static fn(string $f): object => new $f();
            $provider = $instantiator($fqcn);

            $allFqcns[] = $fqcn;

            if ($provider instanceof IsFrameworkBackendProviderInterface
                || $provider instanceof IsFrameworkBackendProviderV2Interface) {
                $frameworkFqcns[] = $fqcn;
            }
        }

        return new BackendRegistrar($allFqcns, $frameworkFqcns, $this->gatewayAudit);
    }
}
