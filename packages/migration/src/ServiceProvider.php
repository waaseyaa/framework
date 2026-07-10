<?php

declare(strict_types=1);

namespace Waaseyaa\Migration;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsContentModelProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsMigrationProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider as BaseServiceProvider;
use Waaseyaa\Migration\ContentModel\ContentModelRegistrar;
use Waaseyaa\Migration\ContentModel\DerivesContentModelInterface;
use Waaseyaa\Migration\Discovery\FilesystemManifestLoader;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\Plugin\Destination\EntityDestinationFactory;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RollbackWalker;

/**
 * Service provider for the migration platform package.
 *
 * Discovered via `extra.waaseyaa.providers` in `packages/migration/composer.json`.
 *
 * WP01 landed the package scaffold and stable plugin surface. WP02 adds the
 * boot-time discovery surface:
 *
 * - {@see FilesystemManifestLoader} singleton, configured from
 *   `config.migration.manifest_paths`.
 * - {@see MigrationRegistry} singleton, built lazily (G-024, #1940): the
 *   singleton factory no longer calls `$registry->boot()`, and `boot()`
 *   below no longer eagerly resolves the registry. `AbstractKernel::boot()`
 *   runs `bootProviders()` (where this provider's `boot()` runs) strictly
 *   BEFORE `discoverAccessPolicies()` builds the `EntityAccessHandler`; a
 *   provider's `migrations()` implementation that resolves
 *   `GateInterface`/`EntityAccessHandler` off the kernel-services bus to
 *   build a destination plugin would find no binding yet and crash kernel
 *   boot. Definitions are now built on the registry's first query — see
 *   {@see MigrationRegistry}'s class docblock for the full contract and the
 *   traded-away fail-fast-at-boot property.
 *
 * Provider capability dispatch — discovering every sibling provider
 * implementing {@see HasMigrationsInterface} — lands when the kernel grows a
 * generic capability bus. Until then this provider exposes a registry
 * constructed with an empty provider list and the filesystem loader only.
 * Tests and host applications that need to inject migration providers may
 * override the binding by constructing {@see MigrationRegistry} directly.
 *
 * Concrete bindings for runner / destination / CLI still land in WP05, WP07,
 * and WP09 respectively.
 *
 * @api
 */
final class ServiceProvider extends BaseServiceProvider implements AcceptsMigrationProvidersInterface, AcceptsContentModelProvidersInterface
{
    /** @var list<HasMigrationsInterface> Providers injected by tests or future capability-dispatch wiring. */
    private array $migrationProviders = [];

    /**
     * @var list<DerivesContentModelInterface> Providers injected by the
     *      kernel's {@see \Waaseyaa\Foundation\Kernel\AbstractKernel::injectContentModelProviders()}
     *      (G-026, #1940), or directly by tests. Only collected here — never
     *      invoked here. See {@see ContentModelRegistrar} and
     *      {@see \Waaseyaa\Migration\Runner\MigrationRunner} for the
     *      import-time invocation point.
     */
    private array $contentModelProviders = [];

    public function register(): void
    {
        $this->singleton(FilesystemManifestLoader::class, function () {
            return new FilesystemManifestLoader(
                manifestPaths: $this->resolveManifestPaths(),
                logger: $this->resolveLogger(),
            );
        });

        $this->singleton(MigrationRegistry::class, function () {
            $loader = $this->resolve(FilesystemManifestLoader::class);
            \assert($loader instanceof FilesystemManifestLoader);

            // G-024 (#1940): no eager boot() here — see this class's
            // docblock and boot() below for why.
            return new MigrationRegistry(
                providers: $this->migrationProviders,
                filesystemLoader: $loader,
            );
        });

        // WP06 — runtime collaborators for the import:* CLI commands.
        // `DatabaseInterface` and `MigrationIdMap` resolve through the kernel
        // service bus when consumers actually run the CLI; the bindings here
        // describe shape only.
        $this->singleton(MigrationIdMap::class, function () {
            $database = $this->resolve(DatabaseInterface::class);
            \assert($database instanceof DatabaseInterface);

            return new MigrationIdMap(
                database: $database,
                logger: $this->resolveLogger(),
            );
        });

        $this->singleton(ProcessChainExecutor::class, static fn(): ProcessChainExecutor
            => new ProcessChainExecutor());

        // WP07 — per-record progress + resume checkpoint repository.
        $this->singleton(MigrationRunState::class, function () {
            $database = $this->resolve(DatabaseInterface::class);
            \assert($database instanceof DatabaseInterface);

            return new MigrationRunState(
                database: $database,
                logger: $this->resolveLogger(),
            );
        });

        $this->singleton(MigrationRunner::class, function () {
            $registry = $this->resolve(MigrationRegistry::class);
            \assert($registry instanceof MigrationRegistry);
            $chain = $this->resolve(ProcessChainExecutor::class);
            \assert($chain instanceof ProcessChainExecutor);
            $idMap = $this->resolve(MigrationIdMap::class);
            \assert($idMap instanceof MigrationIdMap);
            $runState = $this->resolve(MigrationRunState::class);
            \assert($runState instanceof MigrationRunState);
            $contentModelRegistrar = $this->resolve(ContentModelRegistrar::class);
            \assert($contentModelRegistrar instanceof ContentModelRegistrar);

            return new MigrationRunner(
                registry: $registry,
                chain: $chain,
                idMap: $idMap,
                logger: $this->resolveLogger(),
                runState: $runState,
                contentModelRegistrar: $contentModelRegistrar,
                contentModelProviders: $this->contentModelProviders,
            );
        });

        // G-026 (#1940): binds ContentModelRegistrar, blessing it as the one
        // supported path for declaring import-derived per-bundle content
        // models (see that class's docblock for the full contract). Lazy
        // like EntityDestinationFactory above: EntityTypeManager is safe to
        // resolve early, but the registrar itself is only ever INVOKED from
        // MigrationRunner at the first import command — well after full
        // kernel boot — so there is no boot-ordering hazard to guard against
        // here the way there is for EntityDestinationFactory's GateInterface.
        $this->singleton(ContentModelRegistrar::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);
            \assert($entityTypeManager instanceof EntityTypeManager);

            return new ContentModelRegistrar(
                entityTypeManager: $entityTypeManager,
                logger: $this->resolveLogger(),
            );
        });

        // WP08 — rollback orchestrator for `import:rollback`.
        $this->singleton(RollbackWalker::class, function () {
            $registry = $this->resolve(MigrationRegistry::class);
            \assert($registry instanceof MigrationRegistry);
            $idMap = $this->resolve(MigrationIdMap::class);
            \assert($idMap instanceof MigrationIdMap);

            return new RollbackWalker(
                registry: $registry,
                idMap: $idMap,
                logger: $this->resolveLogger(),
            );
        });

        // Adversarial-review follow-up to #1940 (WP-2 rework): binds
        // EntityDestinationFactory, whose own docblock instructs consumers
        // to `$this->resolve(EntityDestinationFactory::class)->create(...)`
        // but which no provider ever bound — every real call threw
        // RuntimeException("No binding registered ..."). The closure is
        // deliberately lazy (mirrors the other singletons in this method):
        // it resolves GateInterface at CLOSURE EXECUTION TIME (first
        // resolve()), not at register(), so it only runs after
        // AbstractKernel::discoverAccessPolicies() has made the gate live
        // (G-014) — resolving this abstract before that point fails exactly
        // like resolving GateInterface directly would.
        $this->singleton(EntityDestinationFactory::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);
            \assert($entityTypeManager instanceof EntityTypeManager);
            $gate = $this->resolve(GateInterface::class);
            \assert($gate instanceof GateInterface);
            $dispatcher = $this->resolve(EventDispatcherInterface::class);
            \assert($dispatcher instanceof EventDispatcherInterface);

            return new EntityDestinationFactory(
                entityTypeManager: $entityTypeManager,
                gate: $gate,
                eventDispatcher: $dispatcher,
                logger: $this->resolveLogger(),
            );
        });
    }

    public function boot(): void
    {
        // G-024 (#1940): deliberately does NOT resolve MigrationRegistry.
        //
        // This provider's boot() runs from AbstractKernel::bootProviders(),
        // strictly BEFORE AbstractKernel::discoverAccessPolicies() builds the
        // EntityAccessHandler. Resolving the registry here would run every
        // provider's migrations() at that point; a provider whose
        // migrations() resolves GateInterface/EntityAccessHandler off the
        // kernel-services bus to build a destination plugin would find no
        // binding yet and crash kernel boot with "No binding registered for
        // ..." (the Sheguiandah pass-1 symptom, which needed a lazy-gate
        // shim to work around).
        //
        // Definitions are now built lazily on the registry's first query
        // (MigrationRegistry::ensureBooted()) — by the time any caller
        // queries it (the import:* CLI commands resolve it per-command, at
        // command execution, well after full kernel boot — see
        // ImportServiceProvider), the whole kernel, including access
        // services, is live.
        //
        // Trade-off, accepted deliberately: structural manifest errors
        // (missing dependency, id collision, dependency cycle) no longer
        // fail fast at framework boot; they now surface at the first CLI
        // invocation that touches the registry instead. See
        // docs/specs/migration-platform.md §9 and CHANGELOG.md (G-024).
    }

    /**
     * Inject migration providers prior to {@see register()}.
     *
     * Implements {@see AcceptsMigrationProvidersInterface}: the kernel discovers
     * providers that expose application migrations and hands them here before
     * `boot()` resolves the registry. Also used directly by the WP02 integration
     * test. The interface param is `list<object>` (Foundation cannot name the
     * Layer-3 `HasMigrationsInterface`), so we filter to migration providers here;
     * the kernel only ever passes those, so the filter is defensive.
     *
     * @param list<object> $providers
     */
    public function withMigrationProviders(array $providers): void
    {
        $this->migrationProviders = \array_values(\array_filter(
            $providers,
            static fn(object $provider): bool => $provider instanceof HasMigrationsInterface,
        ));
    }

    /**
     * Inject content-model providers prior to {@see register()}.
     *
     * Implements {@see AcceptsContentModelProvidersInterface}: the kernel
     * discovers providers that expose an import-derived content model and
     * hands them here (G-026, #1940). Same shape as
     * {@see withMigrationProviders()} — collection only, no invocation. The
     * providers are threaded into the {@see MigrationRunner} binding above
     * and invoked from there, at the first import command, not from this
     * provider's `boot()`.
     *
     * @param list<object> $providers
     */
    public function withContentModelProviders(array $providers): void
    {
        $this->contentModelProviders = \array_values(\array_filter(
            $providers,
            static fn(object $provider): bool => $provider instanceof DerivesContentModelInterface,
        ));
    }

    /**
     * @return list<string>
     */
    private function resolveManifestPaths(): array
    {
        $migrationConfig = $this->config['migration'] ?? [];
        if (!\is_array($migrationConfig)) {
            return [];
        }
        $paths = $migrationConfig['manifest_paths'] ?? [];
        if (!\is_array($paths)) {
            return [];
        }
        $normalised = [];
        foreach ($paths as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                $normalised[] = $candidate;
            }
        }
        return $normalised;
    }

    private function resolveLogger(): ?LoggerInterface
    {
        if ($this->kernelServices === null) {
            return null;
        }
        $logger = $this->kernelServices->get(LoggerInterface::class);
        return $logger instanceof LoggerInterface ? $logger : null;
    }
}
