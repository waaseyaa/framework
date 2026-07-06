<?php

declare(strict_types=1);

namespace Waaseyaa\Config;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyComponentEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyContractEventDispatcherInterface;
use Waaseyaa\Config\Storage\FileStorage;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Binds `ConfigFactoryInterface` to a singleton `ConfigFactory` backed by the
 * same active config store the rest of the boot uses: `<projectRoot>/config/active`
 * via `FileStorage` — the exact directory `Waaseyaa\CLI\Provider\OptimizeServiceProvider`
 * compiles from for `optimize:config` (there is exactly one active store; this
 * binding does not construct a second one).
 *
 * Before this provider existed, NO production ServiceProvider bound
 * `ConfigFactoryInterface`/`ConfigFactory` anywhere in the framework (confirmed
 * by grep across packages/*\/src and the kernel bootstrappers). Any consumer
 * reaching for it via `resolveOptional(ConfigFactoryInterface::class)` —
 * `Waaseyaa\SSR\ThemeServiceProvider`, and the CW-v1 `WorkflowServiceProvider`
 * guard/seed wiring — silently received `null` and no-opped in a real boot.
 * See #1920 WP-1 follow-up.
 */
final class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $root = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();

        $this->singleton(ConfigFactoryInterface::class, function () use ($root): ConfigFactory {
            $storage = new FileStorage($root . '/config/active');

            return new ConfigFactory($storage, $this->resolveEventDispatcher());
        });
    }

    /**
     * The kernel-services bus only recognizes the Symfony-contracts FQCN
     * (`Symfony\Contracts\EventDispatcher\EventDispatcherInterface`) — see
     * `ProviderRegistryKernelServices::get()`. `ConfigFactory` itself is typed
     * against the wider Symfony-component interface
     * (`Symfony\Component\EventDispatcher\EventDispatcherInterface`, which
     * extends the contracts one). The kernel's real dispatcher
     * (`SymfonyEventDispatcherAdapter`) implements both, so resolving through
     * the contracts FQCN and handing the same instance to `ConfigFactory`
     * satisfies both type-checks. Falls back to a fresh dispatcher only when
     * no kernel context exposes one at all (e.g. unit construction sites),
     * matching the null-safe defaults used elsewhere in this package's tests.
     */
    private function resolveEventDispatcher(): SymfonyComponentEventDispatcherInterface
    {
        $dispatcher = $this->resolveOptional(SymfonyContractEventDispatcherInterface::class);

        if ($dispatcher instanceof SymfonyComponentEventDispatcherInterface) {
            return $dispatcher;
        }

        return new EventDispatcher();
    }
}
