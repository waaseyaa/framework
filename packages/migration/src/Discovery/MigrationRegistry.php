<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Discovery;

use Waaseyaa\Migration\Exception\MigrationCycleException;
use Waaseyaa\Migration\Exception\MigrationDependencyMissingException;
use Waaseyaa\Migration\Exception\MigrationPluginCollisionException;
use Waaseyaa\Migration\MigrationDefinition;

/**
 * Indexes every {@see MigrationDefinition} contributed by providers or the
 * filesystem loader, validates dependencies, and exposes the resulting DAG
 * for downstream consumers (FR-013, FR-014, FR-015, FR-017).
 *
 * Lifecycle (lazy since G-024, #1940):
 *
 * 1. Construct with the providers implementing {@see HasMigrationsInterface}
 *    and an optional {@see FilesystemManifestLoader}.
 * 2. The index is built on the FIRST call to any query method ({@see get()},
 *    {@see has()}, {@see all()}, {@see topologicallySorted()},
 *    {@see graph()}) via a private `ensureBooted()` that runs the same
 *    ingestion/validation/graph-construction logic {@see boot()} always has.
 *    Callers may still call {@see boot()} explicitly and get the identical
 *    result — `ensureBooted()` is a no-op once `boot()` has run, whichever
 *    triggered it. Either way, the registry consumes both sources, indexes
 *    the definitions by id (collisions raise
 *    {@see MigrationPluginCollisionException}), validates every declared
 *    dependency exists (missing raises
 *    {@see MigrationDependencyMissingException}), and constructs the
 *    {@see DependencyGraph}. Cycles raise {@see MigrationCycleException}.
 * 3. Once built, the registry is immutable. Any further explicit call to
 *    `boot()` raises `\LogicException` (programmer error).
 *
 * Why lazy: `AbstractKernel::boot()` runs `bootProviders()` (where the
 * migration package's `ServiceProvider::boot()` used to eagerly resolve this
 * registry) strictly BEFORE `discoverAccessPolicies()` builds the
 * `EntityAccessHandler`. A provider's `migrations()` implementation that
 * resolves `GateInterface`/`EntityAccessHandler` from the kernel-services bus
 * to build a destination plugin would find no binding yet and crash kernel
 * boot ("No binding registered for ..." — the Sheguiandah pass-1 symptom,
 * which needed a lazy-gate shim to work around). Deferring index-building to
 * first query trades away fail-fast-at-boot for structural manifest errors
 * (missing dependency, id collision, dependency cycle now surface at the
 * first CLI invocation that touches the registry, not at framework startup)
 * in exchange for every provider's `migrations()` running only once the
 * whole kernel — including access services — is live. See
 * `docs/specs/migration-platform.md` §9 and CHANGELOG.md (G-024).
 *
 * @api
 */
final class MigrationRegistry
{
    /** @var array<string, MigrationDefinition> */
    private array $definitions = [];

    private ?DependencyGraph $graph = null;

    private bool $booted = false;

    /**
     * @param iterable<HasMigrationsInterface> $providers Providers that ship migrations as part of their `extra.waaseyaa.providers` surface.
     * @param ?FilesystemManifestLoader $filesystemLoader Optional loader scanning `config/waaseyaa.php` `migration.manifest_paths`.
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly ?FilesystemManifestLoader $filesystemLoader = null,
    ) {}

    /**
     * Single-shot boot. Idempotent calls raise `\LogicException` so callers
     * cannot accidentally re-register after the registry has been frozen.
     *
     * @throws MigrationPluginCollisionException When two sources register the same migration id.
     * @throws MigrationDependencyMissingException When a definition declares a dependency that is not registered.
     * @throws MigrationCycleException When the dependency graph contains a cycle.
     * @throws \LogicException When called more than once.
     */
    public function boot(): void
    {
        if ($this->booted) {
            throw new \LogicException('MigrationRegistry::boot() may only be called once per registry.');
        }

        $this->ingestProviderDefinitions();
        $this->ingestFilesystemDefinitions();
        $this->validateDependencies();
        $this->graph = DependencyGraph::fromDefinitions($this->definitions);
        $this->detectCycles($this->graph);

        $this->booted = true;
    }

    /**
     * Fetch a registered definition. Builds the index lazily on first call
     * (G-024) if `boot()` has not already run.
     *
     * @throws \OutOfBoundsException When `$id` is not registered.
     */
    public function get(string $id): MigrationDefinition
    {
        $this->ensureBooted();
        if (!isset($this->definitions[$id])) {
            throw new \OutOfBoundsException(\sprintf(
                'MigrationRegistry: migration %s is not registered.',
                \var_export($id, true),
            ));
        }
        return $this->definitions[$id];
    }

    /**
     * Whether a migration with the given id is registered. Builds the index
     * lazily on first call (G-024) if `boot()` has not already run.
     */
    public function has(string $id): bool
    {
        $this->ensureBooted();
        return isset($this->definitions[$id]);
    }

    /**
     * Every registered definition. Order is registration order — for
     * dependency-aware iteration use {@see topologicallySorted()}. Builds the
     * index lazily on first call (G-024) if `boot()` has not already run.
     *
     * @return list<MigrationDefinition>
     */
    public function all(): array
    {
        $this->ensureBooted();
        return \array_values($this->definitions);
    }

    /**
     * Definitions in dependency order — every prerequisite appears before its
     * dependent. Powers `bin/waaseyaa import:run-all` (FR-033). Builds the
     * index lazily on first call (G-024) if `boot()` has not already run.
     *
     * @return list<MigrationDefinition>
     */
    public function topologicallySorted(): array
    {
        $this->ensureBooted();
        \assert($this->graph !== null);

        $ordered = [];
        foreach ($this->graph->topologicalOrder() as $id) {
            $ordered[] = $this->definitions[$id];
        }
        return $ordered;
    }

    /**
     * Read-only handle on the dependency graph. Useful for status displays and
     * `import:rollback` cascade walks. Builds the index lazily on first call
     * (G-024) if `boot()` has not already run.
     */
    public function graph(): DependencyGraph
    {
        $this->ensureBooted();
        \assert($this->graph !== null);
        return $this->graph;
    }

    private function ingestProviderDefinitions(): void
    {
        foreach ($this->providers as $provider) {
            $contributedBy = $provider::class;
            // `HasMigrationsInterface::migrations()` returns
            // `iterable<MigrationDefinition>`; providers that yield other
            // types violate the contract and produce a native TypeError at
            // `indexDefinition()`'s parameter binding.
            foreach ($provider->migrations() as $definition) {
                $this->indexDefinition($definition, $contributedBy);
            }
        }
    }

    private function ingestFilesystemDefinitions(): void
    {
        if ($this->filesystemLoader === null) {
            return;
        }
        foreach ($this->filesystemLoader->load() as $definition) {
            // FilesystemManifestLoader is the canonical "first claimant" for
            // its definitions; collisions report the loader namespace.
            $this->indexDefinition($definition, FilesystemManifestLoader::class);
        }
    }

    private function indexDefinition(MigrationDefinition $definition, string $contributedBy): void
    {
        if (isset($this->definitions[$definition->id])) {
            $existing = $this->definitions[$definition->id];
            throw new MigrationPluginCollisionException(
                pluginId: $definition->id,
                firstFqcn: $existing::class,
                secondFqcn: $contributedBy === MigrationDefinition::class
                    ? $definition::class
                    : $contributedBy,
            );
        }
        $this->definitions[$definition->id] = $definition;
    }

    private function validateDependencies(): void
    {
        foreach ($this->definitions as $id => $definition) {
            foreach ($definition->dependencies as $dependency) {
                if (!isset($this->definitions[$dependency])) {
                    throw new MigrationDependencyMissingException(
                        missingDependencyId: $dependency,
                        requestingMigrationId: $id,
                    );
                }
            }
        }
    }

    private function detectCycles(DependencyGraph $graph): void
    {
        $cycle = new CycleDetector()->detect($graph);
        if ($cycle !== []) {
            throw new MigrationCycleException(cyclePath: $cycle);
        }
    }

    /**
     * Builds the index on the first call (delegating to {@see boot()}); every
     * later call is a no-op. This is the G-024 (#1940) lazy-boot seam every
     * public query method routes through — it lets `boot()` still be called
     * explicitly (and lets a second explicit call keep raising
     * `\LogicException`, preserving the pre-G-024 contract for callers that
     * already do that) while first-query callers get the same guarantees
     * without ever calling `boot()` themselves.
     */
    private function ensureBooted(): void
    {
        if (!$this->booted) {
            $this->boot();
        }
    }
}
