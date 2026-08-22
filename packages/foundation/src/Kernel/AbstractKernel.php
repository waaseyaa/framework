<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyContractEventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Access\Policy\ContentAdminAccessPolicy;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\Audit\EntityWriteAuditListener;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Preflight\FieldAccessClassificationArtifact;
use Waaseyaa\EntityStorage\Backend\BackendRegistrarFactory;
use Waaseyaa\EntityStorage\Backend\DatabaseStrictFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Backend\StrictFieldStorageGatewayAuditInterface;
use Waaseyaa\EntityStorage\BackendResolver;
use Waaseyaa\EntityStorage\Query\DefinitionValidator;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\Foundation\Community\CommunityContextBootstrapper;
use Waaseyaa\Foundation\Community\CommunityContextInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry;
use Waaseyaa\Foundation\Kernel\Bootstrap\AppEntityTypeLoader;
use Waaseyaa\Foundation\Kernel\Bootstrap\ContentTypeValidator;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;
use Waaseyaa\Foundation\Kernel\Bootstrap\KernelPolicyDependencyResolver;
use Waaseyaa\Foundation\Kernel\Bootstrap\KnowledgeExtensionBootstrapper;
use Waaseyaa\Foundation\Kernel\Bootstrap\ManifestBootstrapper;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Kernel\Bootstrap\ScheduleEntryRegistry;
use Waaseyaa\Foundation\Kernel\Preflight\FieldAccessActivationPreflight;
use Waaseyaa\Foundation\Kernel\Preflight\LiveEntitySchemaFingerprint;
use Waaseyaa\Foundation\Log\Handler\ErrorLogHandler as HandlerErrorLogHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogManager;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyComposition;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsAgentToolProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsAiCatalogEntryProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsApiCatalogEntryProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsContentModelProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsMigrationProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesAiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Plugin\Extension\KnowledgeToolingExtensionRunner;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduleInterface;

/**
 * @internal
 * @api
 */
abstract class AbstractKernel
{
    protected EventDispatcherInterface&SymfonyContractEventDispatcherInterface $dispatcher;
    protected DatabaseInterface $database;
    protected EntityTypeManager $entityTypeManager;
    protected ?ScheduleInterface $schedule = null;
    protected ?\Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface $fieldRegistry = null;
    protected PackageManifest $manifest;
    protected EntityAccessHandler $accessHandler;
    protected EntityTypeLifecycleManager $lifecycleManager;
    protected EntityAuditLogger $entityAuditLogger;
    protected Migrator $migrator;
    private ?ApplicationSecret $applicationSecret = null;
    private ?ApplicationMasterRekeyComposition $applicationMasterRekeyComposition = null;
    private readonly RedactorProcessor $sinkSanitizer;
    private readonly bool $rebuildLoggerFromConfig;
    private ?SecretResolverRegistry $secretResolverRegistry = null;
    protected MigrationLoader $migrationLoader;
    protected MigrationRepository $migrationRepository;

    /** @var array<string, mixed> */
    protected array $config = [];

    /** @var list<ServiceProvider> */
    protected array $providers = [];

    private ?KnowledgeToolingExtensionRunner $knowledgeExtensionRunner = null;
    private bool $booted = false;
    private bool $restrictedDiscoveryOnly = false;

    /**
     * Captured while building the repository factory for the explicit legacy
     * mutation-authority repair. No ordinary boot path may enable this flag.
     */
    private bool $allowUnscopedMutationAuthorityRepair = false;
    protected LoggerInterface $logger;

    /**
     * The single per-kernel acting-account context (mission
     * revision-audit-provenance-01KTWY5V WP01, research D1). Constructed
     * lazily behind {@see accountContext()} — every exposure path (the
     * repository factory closure, the kernel-services bus, the handler
     * container, the HTTP middleware) MUST serve this same instance; a
     * second construction site would silently fork the context.
     */
    private ?RequestAccountContext $accountContext = null;
    private ?AccountFieldReadScopeInterface $fieldReadScope = null;

    /**
     * Optional community context for tenancy-scoped entity types
     * (mission #1257 §C1). When bound, the kernel wires `CommunityScope`
     * into the storage driver of every EntityType whose `getTenancy()`
     * returns `['scope' => 'community']`. When `null`, declarative-tenant
     * entity types resolve without scoping and the kernel logs a warning
     * once per registration.
     */
    protected ?CommunityContextInterface $communityContext = null;

    /**
     * Type ids that have already triggered the
     * "tenancy declared but no CommunityContextInterface bound" warning,
     * memoized so the kernel logs it once per repository factory invocation
     * site rather than once per request.
     *
     * @var array<string, true>
     */
    private array $missingCommunityContextWarned = [];

    public function __construct(
        protected readonly string $projectRoot,
        ?LoggerInterface $logger = null,
    ) {
        $this->sinkSanitizer = $logger instanceof LogManager
            ? $logger->sinkSanitizer()
            : new RedactorProcessor();
        $this->rebuildLoggerFromConfig = $logger === null || $logger instanceof LogManager;
        $this->logger = $logger instanceof LogManager ? $logger : new LogManager(
            $logger ?? new HandlerErrorLogHandler(),
            $this->sinkSanitizer,
        );
    }

    /**
     * Boot the kernel. Idempotent — safe to call multiple times.
     *
     * If boot fails partway through, the flag remains unset so the
     * caller can retry after fixing the underlying issue.
     */
    protected function boot(): void
    {
        if ($this->booted) {
            return;
        }

        EnvLoader::load($this->projectRoot . '/.env');

        $this->config = ConfigLoader::load($this->projectRoot . '/config/waaseyaa.php', $this->logger);
        $this->communityContext ??= new CommunityContextBootstrapper()->boot($this->config);

        // Upgrade logger from config.
        if ($this->rebuildLoggerFromConfig && $this->logger instanceof LogManager) {
            $loggingConfig = $this->config['logging'] ?? [];
            if (is_array($loggingConfig) && isset($loggingConfig['channels'])) {
                $this->logger = LogManager::fromConfig($loggingConfig, $this->sinkSanitizer);
            } else {
                $level = LogLevel::fromName((string) ($this->config['log_level'] ?? 'warning')) ?? LogLevel::WARNING;
                $this->logger = new LogManager(new HandlerErrorLogHandler(minimumLevel: $level), $this->sinkSanitizer);
            }
        }

        // Safety guard: refuse to boot with debug enabled in production.
        if ($this->isDebugMode() && !$this->isDevelopmentMode()) {
            throw new \RuntimeException(
                sprintf('APP_DEBUG must not be enabled in production (APP_ENV=%s). Aborting boot.', $this->resolveEnvironment()),
            );
        }

        $this->secretResolverRegistry ??= new SecretResolverRegistry(
            $this->sinkSanitizer,
            $this->resolveEnvironment(),
        );

        // Resolve key custody before any database or provider work. A missing
        // production secret must fail at boot, before encrypted/signed state is
        // read or a partially configured application begins serving requests.
        $configuredSecret = getenv('WAASEYAA_APP_SECRET');
        $this->applicationSecret ??= ApplicationSecret::fromEnvironmentValue(
            is_string($configuredSecret) ? $configuredSecret : null,
            $this->resolveEnvironment(),
        );

        $this->dispatcher         = new SymfonyEventDispatcherAdapter();
        $this->lifecycleManager   = new EntityTypeLifecycleManager($this->projectRoot);
        $this->entityAuditLogger  = new EntityAuditLogger($this->projectRoot);

        $auditListener = new EntityWriteAuditListener($this->entityAuditLogger);
        $this->dispatcher->addListener(\Waaseyaa\Entity\Event\EntityEvents::PRE_SAVE->value, [$auditListener, 'onPreSave']);
        $this->dispatcher->addListener(\Waaseyaa\Entity\Event\EntityEvents::POST_SAVE->value, [$auditListener, 'onPostSave']);
        $this->dispatcher->addListener(\Waaseyaa\Entity\Event\EntityEvents::POST_DELETE->value, [$auditListener, 'onPostDelete']);
        $this->bootDatabase();
        $this->bootEntityTypeManager();
        $this->compileManifest();
        $this->bootMigrations();
        $this->discoverAndRegisterProviders();
        $this->secretResolverRegistry()->freeze();
        $this->injectMigrationProviders();
        $this->injectContentModelProviders();
        $this->injectAgentToolProviders();
        $this->injectApiCatalogEntryProviders();
        $this->injectAiCatalogEntryProviders();
        $this->loadAppEntityTypes();
        $this->validateContentTypes();
        if (!$this->restrictedDiscoveryOnly && !$this->isDevelopmentMode()) {
            $this->assertFieldAccessActivationReady();
            $this->assertProductionEntityStorageSchema();
        }
        if (!$this->restrictedDiscoveryOnly) {
            $this->composeApplicationMasterRekeyOwners();
            $this->bootProviders();
            $this->discoverAccessPolicies();
            $this->installFieldReadRuntime();
            $this->bootScheduleEntries();
            $this->validateQueryDefinitions();
            $this->validateEntitySchemas();
            $this->bootKnowledgeExtensionRunner();
        }

        if (!$this->restrictedDiscoveryOnly) {
            $this->finalizeBoot();
        }

        $this->booted = true;
    }

    /**
     * Last hook before the booted flag is set. HttpKernel overrides to wire HTTP caches and runtime.
     */
    protected function finalizeBoot(): void {}

    protected function bootDatabase(): void
    {
        $this->database = new DatabaseBootstrapper()->boot($this->projectRoot, $this->config, $this->logger);
    }

    protected function bootEntityTypeManager(): void
    {
        $fieldRegistry = new \Waaseyaa\Field\FieldDefinitionRegistry();
        $this->fieldRegistry = $fieldRegistry;
        ContentEntityBase::setFieldRegistry($fieldRegistry);

        // Repository construction is lazy, so capture this boot decision by
        // value rather than consulting mutable kernel state after boot.
        $allowUnscopedMutationAuthorityRepair = $this->allowUnscopedMutationAuthorityRepair;

        $this->entityTypeManager = new EntityTypeManagerFactory()->build(
            database: $this->database,
            dispatcher: $this->dispatcher,
            fieldRegistry: $fieldRegistry,
            logger: $this->logger,
            accessHandlerResolver: fn(): ?EntityAccessHandler => $this->accessHandler ?? null,
            communityScoreResolver: fn(EntityTypeInterface $definition): ?\Waaseyaa\EntityStorage\Tenancy\CommunityScope => $this->resolveCommunityScope(
                $definition,
                $allowUnscopedMutationAuthorityRepair,
            ),
            accountContextAttacher: function (object $repository): void {
                $this->attachAccountContext($repository);
            },
            fieldReadScope: $this->fieldReadScope(),
        );
    }

    /**
     * Bind a community context for tenancy-scoped entity types (mission #1257 §C1).
     *
     * Apps wire this from their bootstrap once a `CommunityContextInterface`
     * binding exists (typically from the request middleware or a CLI
     * configuration step). After this call, every entity type that declares
     * `tenancy: ['scope' => 'community']` resolves with `CommunityScope`
     * injected into its storage driver.
     *
     * Pass `null` to clear the binding (test-suite teardown, multi-tenant
     * boundary changes).
     */
    public function setCommunityContext(?CommunityContextInterface $context): void
    {
        $this->communityContext = $context;
    }

    /**
     * The kernel's request-scoped acting-account context (mission
     * revision-audit-provenance-01KTWY5V FR-002).
     *
     * One instance per kernel: the repository factory closure, the
     * kernel-services bus, the handler container, and the HTTP session
     * middleware all share the object returned here. Public because tests
     * and entry points may need it in addition to the HttpKernel subclass;
     * the L1 import rides the Kernel/ cross-layer exemption.
     */
    public function accountContext(): AccountContextInterface
    {
        return $this->accountContext ??= new RequestAccountContext();
    }

    /**
     * revision-audit-provenance-01KTWY5V WP01: forward seam — EntityRepository
     * gains setAccountContext() in WP02 of this mission; until then this is a
     * deliberate no-op (method_exists precedent: loadRevision() hydration).
     *
     * The parameter is typed `object` on purpose: EntityRepository is final
     * and does not yet declare the method, so a precisely-typed variable
     * would let PHPStan prove the method_exists() guard always-false and
     * flag the seam. Do NOT replace this with a named `accountContext:`
     * constructor argument — it will not compile until WP02 lands.
     */
    private function attachAccountContext(object $repository): void
    {
        if (method_exists($repository, 'setAccountContext')) {
            $repository->setAccountContext($this->accountContext());
        }
    }

    /**
     * Build a `CommunityScope` for the storage driver of a tenant-scoped
     * entity type, or return null when scoping is not requested.
     *
     * Tenancy is opt-in declarative metadata on the EntityType. When a type
     * declares `tenancy: ['scope' => 'community']`:
     *   - If a `CommunityContextInterface` is bound, wire it.
     *   - If not bound and the kernel runs in development (`local`, `dev`,
     *     `development`, `testing`), log a once-per-type warning and fall
     *     back to a null scope so tests / CLI / bare bootstrap don't crash.
     *   - If not bound and the kernel runs in production, throw. A null
     *     scope in production silently disables community isolation —
     *     every read passes through unfiltered. That is a data-leak
     *     posture, not a tolerable misconfiguration. Fail loud at boot.
     */
    private function resolveCommunityScope(
        EntityTypeInterface $definition,
        bool $allowUnscopedMutationAuthorityRepair = false,
    ): ?CommunityScope {
        $tenancy = $definition->getTenancy();
        if ($tenancy === null) {
            return null;
        }

        // EntityType's constructor validates the slot shape; reaching this
        // branch implies scope === 'community'. The future-scope guard
        // remains so a follow-on (region, org, etc.) lands as a deliberate
        // change rather than a silent fall-through.
        if ($tenancy['scope'] !== 'community') {
            return null;
        }

        // This one explicit pre-runtime repair must inspect every persisted
        // row so it can restore the row's own tenant/type/id-bound authority.
        // Capturing the permission during its dedicated restricted boot keeps
        // ordinary production repositories fail-closed and scoped.
        if ($allowUnscopedMutationAuthorityRepair) {
            return null;
        }

        if ($this->communityContext === null) {
            $typeId = $definition->id();

            if (!$this->isDevelopmentMode()) {
                throw new \RuntimeException(\sprintf(
                    '[TENANCY_MISCONFIGURED] Entity type "%s" declares tenancy [scope=>community] '
                    . 'but no CommunityContextInterface is bound on the kernel. '
                    . 'In production, this would silently disable community isolation on every read — '
                    . 'a data-leak posture, not a tolerable misconfiguration. '
                    . 'Wire $kernel->setCommunityContext() during app bootstrap. '
                    . 'See docs/specs/entity-system.md §Community Scoping.',
                    $typeId,
                ));
            }

            if (!isset($this->missingCommunityContextWarned[$typeId])) {
                $this->missingCommunityContextWarned[$typeId] = true;
                $this->logger->warning(
                    \sprintf(
                        'Entity type "%s" declares tenancy [scope=>community] but no CommunityContextInterface '
                        . 'is bound on the kernel; CommunityScope injection is skipped (development mode). '
                        . 'Wire $kernel->setCommunityContext() during app bootstrap. '
                        . 'In production this would throw. See docs/specs/entity-system.md §Community Scoping.',
                        $typeId,
                    ),
                    [
                        'entity_type' => $typeId,
                        'mission' => '1257',
                        'contract' => 'C1',
                        'environment' => $this->resolveEnvironment(),
                    ],
                );
            }

            return null;
        }

        return new CommunityScope($this->communityContext);
    }

    protected function compileManifest(): void
    {
        // Dev mode compiles the manifest fresh each boot so newly added app
        // entity types and access policies are discovered without
        // `composer dump-autoload -o` or `optimize:manifest`. Production uses the
        // compiled cache.
        $this->manifest = new ManifestBootstrapper()->boot(
            $this->projectRoot,
            freshCompile: $this->isDevelopmentMode(),
        );
    }

    protected function bootMigrations(): void
    {
        // Reuse the DBAL connection from bootDatabase() instead of creating a second one.
        assert($this->database instanceof DBALDatabase);
        $connection = $this->database->getConnection();

        $this->migrationRepository = new MigrationRepository($connection);

        $this->migrationLoader = new MigrationLoader($this->projectRoot, $this->manifest);
        $this->migrator = new Migrator($connection, $this->migrationRepository);
    }

    protected function discoverAndRegisterProviders(): void
    {
        $registry = new ProviderRegistry($this->logger);
        $this->providers = $registry->discoverAndRegister(
            $this->manifest,
            $this->projectRoot,
            $this->config,
            $this->entityTypeManager,
            $this->database,
            $this->dispatcher,
            $this->accountContext(),
            // C-12: lazy access-handler accessor exposed to providers (e.g.
            // AiToolsServiceProvider's tool registry). Lazy + isset-guarded
            // because $this->accessHandler is populated later by
            // discoverAccessPolicies(); it is read only at tool dispatch.
            fn(): ?EntityAccessHandler => $this->accessHandler ?? null,
            $this->applicationSecret(),
            $this->fieldReadScope(),
            requestContext: $this->requestContextForProviders(),
            communityContext: $this->communityContext,
            secretResolverRegistry: $this->secretResolverRegistry(),
        );
    }

    /** Return the kernel-owned secret after the early boot guard has run. */
    protected function applicationSecret(): ApplicationSecret
    {
        if ($this->applicationSecret === null) {
            throw new \LogicException('Application-secret custody is unavailable before kernel boot.');
        }

        return $this->applicationSecret;
    }

    /** Return the one kernel-owned resolver registry after environment policy is sealed. */
    protected function secretResolverRegistry(): SecretResolverRegistry
    {
        if ($this->secretResolverRegistry === null) {
            throw new \LogicException('Secret resolution is unavailable before kernel boot.');
        }

        return $this->secretResolverRegistry;
    }

    /** The single process/request scope shared by the accessor guard and HTTP middleware. */
    protected function fieldReadScope(): AccountFieldReadScopeInterface
    {
        return $this->fieldReadScope ??= new AccountFieldReadScope();
    }

    protected function loadAppEntityTypes(): void
    {
        new AppEntityTypeLoader($this->logger)->load($this->projectRoot, $this->entityTypeManager);
    }

    /**
     * Feed sibling providers implementing the migration package's
     * {@see \Waaseyaa\Migration\Discovery\HasMigrationsInterface} into the
     * migration ServiceProvider so the {@see \Waaseyaa\Migration\Discovery\MigrationRegistry}
     * it exposes discovers application migrations.
     *
     * The migration package ships `withMigrationProviders()` for exactly this
     * "until the kernel grows a generic capability bus" seam (see that package's
     * ServiceProvider docblock). This is that bus, scoped to migrations: it runs
     * after register() (so $this->providers is populated) and before
     * bootProviders() (so the migration provider's eager registry resolve in
     * boot() sees the injected providers).
     *
     * Layer note: AbstractKernel is the application bootstrapper and may wire
     * across layers (CLAUDE.md "Kernel/ exemption"). String FQCNs are used so
     * Foundation carries no compile-time edge to the Layer-3 migration package;
     * if that package is absent the step is a no-op.
     */
    protected function injectMigrationProviders(): void
    {
        $hasMigrationsFqcn = 'Waaseyaa\\Migration\\Discovery\\HasMigrationsInterface';

        if (!\interface_exists($hasMigrationsFqcn)) {
            return; // migration package not installed — nothing to wire.
        }

        $migrationProviders = [];
        foreach ($this->providers as $provider) {
            if ($provider instanceof $hasMigrationsFqcn) {
                $migrationProviders[] = $provider;
            }
        }

        if ($migrationProviders === []) {
            return;
        }

        // The receiving provider opts in via the Foundation capability interface,
        // so the call site is guarded by a named interface (not a concrete FQCN)
        // and the contract test can lock it in step.
        foreach ($this->providers as $provider) {
            if ($provider instanceof AcceptsMigrationProvidersInterface) {
                $provider->withMigrationProviders($migrationProviders);
            }
        }
    }

    /**
     * Feed sibling providers implementing the migration package's
     * {@see \Waaseyaa\Migration\ContentModel\DerivesContentModelInterface}
     * into the migration ServiceProvider so an import command can register
     * their derived content models before the first migration runs
     * (G-026, #1940).
     *
     * Mirrors {@see injectMigrationProviders()} exactly — same "until the
     * kernel grows a generic capability bus" seam, same string-FQCN guard so
     * Foundation carries no compile-time edge to the Layer-3 migration
     * package, same collect-at-boot timing. The critical difference from the
     * pass-1 failure this fixes: this method only COLLECTS provider object
     * references here; nothing here calls `deriveContentModel()`. Full
     * registration happens later in `Waaseyaa\Migration\Runner\MigrationRunner`.
     * The receiving provider may also replay field declarations during its
     * boot, but only for bundle config entities already persisted by an import
     * (#1982), so the first-install sequencing guarantee remains intact.
     */
    protected function injectContentModelProviders(): void
    {
        $derivesContentModelFqcn = 'Waaseyaa\\Migration\\ContentModel\\DerivesContentModelInterface';

        if (!\interface_exists($derivesContentModelFqcn)) {
            return; // migration package not installed — nothing to wire.
        }

        $contentModelProviders = [];
        foreach ($this->providers as $provider) {
            if ($provider instanceof $derivesContentModelFqcn) {
                $contentModelProviders[] = $provider;
            }
        }

        if ($contentModelProviders === []) {
            return;
        }

        foreach ($this->providers as $provider) {
            if ($provider instanceof AcceptsContentModelProvidersInterface) {
                $provider->withContentModelProviders($contentModelProviders);
            }
        }
    }

    /**
     * Feed application providers implementing the ai-tools contributor
     * contract into the registry-owning provider before any provider boots.
     *
     * The string FQCN preserves Foundation's Layer-0 boundary while the named
     * receiver capability keeps this kernel call explicit and contract-tested.
     */
    protected function injectAgentToolProviders(): void
    {
        $providesAgentToolsFqcn = 'Waaseyaa\\AI\\Tools\\ProvidesAgentToolsInterface';

        if (!\interface_exists($providesAgentToolsFqcn)) {
            return;
        }

        $toolProviders = [];
        foreach ($this->providers as $provider) {
            if ($provider instanceof $providesAgentToolsFqcn) {
                $toolProviders[] = $provider;
            }
        }

        if ($toolProviders === []) {
            return;
        }

        usort(
            $toolProviders,
            static fn(object $left, object $right): int => $left::class <=> $right::class,
        );

        foreach ($this->providers as $provider) {
            if ($provider instanceof AcceptsAgentToolProvidersInterface) {
                $provider->withAgentToolProviders($toolProviders);
            }
        }
    }

    /** Feed installed public-API contributors into the RFC 9727 catalog owner. */
    protected function injectApiCatalogEntryProviders(): void
    {
        $contributors = array_values(array_filter(
            $this->providers,
            static fn(object $provider): bool => $provider instanceof ProvidesApiCatalogEntriesInterface,
        ));
        usort(
            $contributors,
            static fn(object $left, object $right): int => $left::class <=> $right::class,
        );

        foreach ($this->providers as $provider) {
            if ($provider instanceof AcceptsApiCatalogEntryProvidersInterface) {
                $provider->withApiCatalogEntryProviders($contributors);
            }
        }
    }

    /** Feed installed public-artifact contributors into the AI Catalog owner. */
    protected function injectAiCatalogEntryProviders(): void
    {
        $contributors = array_values(array_filter(
            $this->providers,
            static fn(object $provider): bool => $provider instanceof ProvidesAiCatalogEntriesInterface,
        ));
        usort(
            $contributors,
            static fn(object $left, object $right): int => $left::class <=> $right::class,
        );

        foreach ($this->providers as $provider) {
            if ($provider instanceof AcceptsAiCatalogEntryProvidersInterface) {
                $provider->withAiCatalogEntryProviders($contributors);
            }
        }
    }

    protected function validateContentTypes(): void
    {
        new ContentTypeValidator()->validate(
            $this->entityTypeManager,
            $this->lifecycleManager->getDisabledTypeIds(),
        );
    }

    /** Refuse a production boot unless its exact deployment inventory was clean. */
    private function assertFieldAccessActivationReady(): void
    {
        $version = is_file($this->projectRoot . '/VERSION')
            ? trim((string) file_get_contents($this->projectRoot . '/VERSION'))
            : 'dev';
        if (is_file($this->projectRoot . '/composer.lock')) {
            $version .= '@' . substr(hash_file('sha256', $this->projectRoot . '/composer.lock'), 0, 16);
        }
        $version = FieldAccessClassificationArtifact::load($this->projectRoot)->bindToFrameworkVersion($version);

        if (!$this->database instanceof DBALDatabase) {
            throw new \RuntimeException('Field-read activation preflight requires the portable DBAL schema manager.');
        }
        // Only entity-storage tables participate in the fingerprint (#2143):
        // first-party services materialize non-entity tables lazily on first
        // use in production (rate limiting, publishing idempotency, …), and an
        // all-table fingerprint made the request AFTER any such first use fail
        // boot as stale. LiveEntitySchemaFingerprint stays in lockstep with the
        // artifact side (DatabaseFieldAccessInventoryScanner) via the shared
        // EntityStorageSchemaShape canonicalization.
        $schemaFingerprint = LiveEntitySchemaFingerprint::compute(
            $this->database,
            array_keys($this->entityTypeManager->getDefinitions()),
        );

        new FieldAccessActivationPreflight()->assertReady($this->projectRoot, $version, $schemaFingerprint);
    }

    /**
     * Production HTTP/CLI runtime must not CREATE missing entity tables (#2478).
     *
     * Field-access preflight fingerprints the physical schema before provider
     * boot. Attachment and other entity schema must already exist from
     * `install:init` / `schema:sync`. Missing tables fail closed here, before
     * any provider `boot()` hook can mutate them.
     */
    private function assertProductionEntityStorageSchema(): void
    {
        $registry = $this->fieldRegistry;
        if ($registry === null) {
            throw new \LogicException('Production entity-schema assertion requires the field registry.');
        }
        new EntityTypeManagerFactory()->assertRegisteredRuntimeSchemas(
            $this->database,
            $this->entityTypeManager,
            $registry,
            $this->logger,
        );
    }

    protected function bootProviders(): void
    {
        new ProviderRegistry($this->logger)->boot($this->providers);
    }

    /** Freeze the complete active owner graph before any provider boot hook executes. */
    private function composeApplicationMasterRekeyOwners(): void
    {
        $this->applicationMasterRekeyComposition = null;
        $composition = ApplicationMasterRekeyComposition::fromProviders(
            $this->database,
            $this->providers,
        );
        $this->applicationMasterRekeyComposition = $composition;
    }

    protected function discoverAccessPolicies(): void
    {
        $providers = $this->providers;
        $kernelServices = new ProviderRegistryKernelServices(
            $this->entityTypeManager,
            $this->database,
            $this->dispatcher,
            $this->logger,
            static fn() => $providers,
            $this->accountContext(),
            manifest: $this->manifest,
            fieldReadScope: $this->fieldReadScope(),
            requestContext: $this->requestContextForProviders(),
            communityContext: $this->communityContext,
            secretResolverRegistry: $this->secretResolverRegistry(),
        );
        $resolver = new KernelPolicyDependencyResolver($kernelServices);
        $this->accessHandler = new AccessPolicyRegistry($this->logger, $resolver)->discover($this->manifest);

        // Framework default: published content (entity types in the `content`
        // group) is anonymously viewable with no hand-written per-type policy.
        // Additive only (never Forbidden), so a specific policy's denial wins.
        $this->accessHandler->addPolicy(new PublishedContentAccessPolicy($this->entityTypeManager));

        // Framework default: an account holding `administer content` may fully
        // manage any content-group entity (view/update/delete/create, drafts
        // included) with no hand-written per-type policy — the per-group analogue
        // of NodeAccessPolicy's `administer nodes` bypass. Additive (never
        // Forbidden); gated strictly on `administer content`, so anonymous and
        // the public/MCP read path keep published-view-only.
        $this->accessHandler->addPolicy(new ContentAdminAccessPolicy($this->entityTypeManager));
    }

    /** Install the process guard with the same scope singleton used by HTTP middleware. */
    private function installFieldReadRuntime(): void
    {
        $providers = $this->providers;
        $kernelServices = new ProviderRegistryKernelServices(
            $this->entityTypeManager,
            $this->database,
            $this->dispatcher,
            $this->logger,
            static fn() => $providers,
            $this->accountContext(),
            fn(): EntityAccessHandler => $this->accessHandler,
            $this->manifest,
            $this->applicationSecret,
            $this->fieldReadScope(),
            requestContext: $this->requestContextForProviders(),
            communityContext: $this->communityContext,
            secretResolverRegistry: $this->secretResolverRegistry(),
        );
        $scope = $kernelServices->get(AccountFieldReadScopeInterface::class);
        if (!$scope instanceof AccountFieldReadScopeInterface) {
            throw new \LogicException('Field-read activation requires the shared account field-read scope binding.');
        }

        EntityReadRuntime::installGuard(new FieldReadGuard(
            $scope,
            $this->accessHandler->checkProtectedFieldRead(...),
        ));
    }

    /**
     * Boot schedule entries discovered in the package manifest (FR-003, FR-004, FR-007).
     *
     * Creates a fresh Schedule, instantiates each ScheduleEntriesInterface class
     * via PolicyDependencyResolverInterface (M-B resolver adopted — same DI semantics
     * as AccessPolicyRegistry), and calls register() on each. Entries listed in
     * `schedule.disabled_entries` are silently skipped. Fail-closed: an unresolvable
     * constructor dependency throws ScheduleEntryInstantiationException and aborts boot.
     *
     * Placed after discoverAccessPolicies() so all provider bindings are live.
     */
    /**
     * The live request's {@see \Waaseyaa\Foundation\Http\RequestContext}, or
     * null when this kernel is not serving an HTTP request (#2167).
     *
     * Console and CLI kernels keep the null default, so their consumers see the
     * anonymous context their own provider binds — unchanged behaviour.
     * {@see \Waaseyaa\Foundation\Kernel\HttpKernel} overrides this to supply
     * the request's actual query parameters, which is what lets `?page=` and
     * exposed filter parameters reach the Listing pipeline.
     */
    protected function requestContextForProviders(): ?\Waaseyaa\Foundation\Http\RequestContext
    {
        return null;
    }

    protected function bootScheduleEntries(): void
    {
        $this->schedule = new Schedule();

        $providers = $this->providers;
        $kernelServices = new ProviderRegistryKernelServices(
            $this->entityTypeManager,
            $this->database,
            $this->dispatcher,
            $this->logger,
            static fn() => $providers,
            $this->accountContext(),
            manifest: $this->manifest,
            fieldReadScope: $this->fieldReadScope(),
            requestContext: $this->requestContextForProviders(),
            communityContext: $this->communityContext,
            secretResolverRegistry: $this->secretResolverRegistry(),
        );
        $resolver = new KernelPolicyDependencyResolver($kernelServices);
        new ScheduleEntryRegistry($this->logger, $resolver)
            ->boot($this->manifest, $this->schedule, $this->config);
    }

    /**
     * Enforce FR-021 fail-fast contract: every indexed field must be backed by
     * a backend that returns true from supportsQuery(). Called once at boot,
     * after all service providers have registered their entity types, and before
     * the booted flag is set. Boot fails immediately on the first violation —
     * there is no silent fallback and no runtime retry.
     *
     * @throws \Waaseyaa\EntityStorage\Exception\UnsupportedQueryException
     */
    protected function validateQueryDefinitions(): void
    {
        $providers = $this->providers;
        $kernelServices = new ProviderRegistryKernelServices(
            $this->entityTypeManager,
            $this->database,
            $this->dispatcher,
            $this->logger,
            static fn() => $providers,
            $this->accountContext(),
            manifest: $this->manifest,
            applicationSecret: $this->applicationSecret,
            fieldReadScope: $this->fieldReadScope(),
            requestContext: $this->requestContextForProviders(),
            communityContext: $this->communityContext,
            secretResolverRegistry: $this->secretResolverRegistry(),
        );
        $gatewayAudit = $kernelServices->get(StrictFieldStorageGatewayAuditInterface::class);
        $gatewayAudit = $gatewayAudit instanceof StrictFieldStorageGatewayAuditInterface
            ? $gatewayAudit
            : new DatabaseStrictFieldStorageGatewayAudit($this->database);
        $registrar = new BackendRegistrarFactory(
            $this->manifest->providers,
            gatewayAudit: $gatewayAudit,
        )->create();
        $registrar->build();

        $resolver = new BackendResolver($registrar);
        new DefinitionValidator($this->entityTypeManager, $resolver, $this->logger)->validateAll();
    }

    /**
     * Boot-time guard that a registered entity type actually has a backing table.
     *
     * Entity tables are created by `schema:sync` / `install:init`, not lazily
     * on first storage access. This check is **opt-in** and OFF by default for
     * local/development. Production runtime always asserts the complete
     * registered shape before provider boot (#2478). Enable the opt-in after
     * running `schema:sync` / `db:init --sync-schema` to assert the deploy left
     * no registered entity type tableless:
     *
     *   - `off`    (default) — no-op.
     *   - `warn`   — log a warning naming the tableless entity types.
     *   - `strict` — throw and abort boot on the first missing table.
     *
     * Resolution: env `WAASEYAA_SCHEMA_VALIDATION` > config `entity_schema_validation` > `off`.
     *
     * @throws \RuntimeException In `strict` mode when a registered type has no table.
     */
    protected function validateEntitySchemas(): void
    {
        $mode = $this->resolveSchemaValidationMode();
        if ($mode === 'off') {
            return;
        }

        $schema = $this->database->schema();
        $missing = [];
        foreach ($this->entityTypeManager->getDefinitions() as $type) {
            if (!$schema->tableExists($type->id())) {
                $missing[] = $type->id();
            }
        }

        if ($missing === []) {
            return;
        }

        sort($missing);
        $message = sprintf(
            'Entity schema validation: %d registered entity type(s) have no table: %s. '
            . 'Run `waaseyaa schema:sync` (or `waaseyaa db:init --sync-schema`) to materialize them.',
            count($missing),
            implode(', ', $missing),
        );

        if ($mode === 'strict') {
            throw new \RuntimeException($message);
        }

        $this->logger->warning($message);
    }

    /**
     * @return 'off'|'warn'|'strict'
     */
    private function resolveSchemaValidationMode(): string
    {
        $env = getenv('WAASEYAA_SCHEMA_VALIDATION');
        $raw = is_string($env) && $env !== ''
            ? $env
            : ($this->config['entity_schema_validation'] ?? 'off');
        $value = is_string($raw) ? strtolower($raw) : 'off';

        return in_array($value, ['off', 'warn', 'strict'], true) ? $value : 'off';
    }

    protected function bootKnowledgeExtensionRunner(): void
    {
        $this->knowledgeExtensionRunner = new KnowledgeExtensionBootstrapper($this->logger)
            ->boot($this->projectRoot, $this->config);
    }

    public function getKnowledgeToolingExtensionRunner(): KnowledgeToolingExtensionRunner
    {
        if ($this->knowledgeExtensionRunner === null) {
            $this->knowledgeExtensionRunner = new KnowledgeToolingExtensionRunner([]);
        }

        return $this->knowledgeExtensionRunner;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function applyWorkflowExtensionContext(array $context): array
    {
        return $this->getKnowledgeToolingExtensionRunner()->applyWorkflowContext($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function applyTraversalExtensionContext(array $context): array
    {
        return $this->getKnowledgeToolingExtensionRunner()->applyTraversalContext($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function applyDiscoveryExtensionContext(array $context): array
    {
        return $this->getKnowledgeToolingExtensionRunner()->applyDiscoveryContext($context);
    }

    public function getLifecycleManager(): EntityTypeLifecycleManager
    {
        return $this->lifecycleManager;
    }

    public function getEntityAuditLogger(): EntityAuditLogger
    {
        return $this->entityAuditLogger;
    }

    /**
     * Whether debug mode is enabled.
     * Resolution: APP_DEBUG env var > config 'debug' key > false.
     */
    protected function isDebugMode(): bool
    {
        $envValue = getenv('APP_DEBUG');
        if (is_string($envValue) && $envValue !== '') {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($this->config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the application is running in a development environment.
     * Resolution: config 'environment' key > APP_ENV env var > 'production'.
     */
    protected function isDevelopmentMode(): bool
    {
        return in_array(strtolower($this->resolveEnvironment()), ['dev', 'development', 'local', 'testing'], true);
    }

    /**
     * Resolve the current environment name from config or env var.
     * Single canonical source for environment resolution.
     */
    protected function resolveEnvironment(): string
    {
        $env = $this->config['environment'] ?? getenv('APP_ENV') ?: 'production';

        return is_string($env) ? $env : 'production';
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Boot the kernel for CLI use without running a console application.
     *
     * Exposes the protected boot() for callers that need a fully-booted kernel
     * (providers, entity type manager, database, dispatcher) without dispatching
     * a command. The Symfony Console application factory uses this path before
     * registering provider commands.
     *
     * @internal Called by ConsoleApplicationFactory to obtain booted providers and the handler container.
     */
    public function bootForCli(): void
    {
        $this->boot();
    }

    /**
     * Restricted names-only bootstrap for the exact field-access preflight.
     * Provider registration and model discovery run; provider boot hooks,
     * policies/capability execution, schedulers, query execution, HTTP, and
     * knowledge extensions do not.
     *
     * @internal
     */
    public function bootForFieldAccessPreflight(): void
    {
        $this->bootForRestrictedDiscovery();
    }

    /**
     * Definition-only bootstrap for the explicit schema transition commands.
     *
     * Entity definitions and migration providers are discovered, but provider
     * boot hooks, repositories, policies, schedulers, queries, and HTTP runtime
     * are not activated. This lets schema:sync repair a missing runtime schema
     * without reintroducing lazy DDL during ordinary kernel boot.
     *
     * @internal
     */
    public function bootForSchemaSync(): void
    {
        $this->bootForRestrictedDiscovery();
    }

    /**
     * Restricted discovery for the explicit pre-DB-03 authority repair.
     *
     * Unlike ordinary and schema-sync boot, its repository factory is allowed
     * to scan tenant-scoped storage without an active community so every row's
     * own tenant binding can be repaired. The permission is captured by value
     * while the factory is built and cannot leak into another boot path.
     *
     * @internal
     */
    public function bootForMutationAuthorityBackfill(): void
    {
        $previous = $this->allowUnscopedMutationAuthorityRepair;
        $this->allowUnscopedMutationAuthorityRepair = true;
        try {
            $this->bootForRestrictedDiscovery();
        } finally {
            $this->allowUnscopedMutationAuthorityRepair = $previous;
        }
    }

    private function bootForRestrictedDiscovery(): void
    {
        $previous = $this->restrictedDiscoveryOnly;
        $this->restrictedDiscoveryOnly = true;
        try {
            $this->boot();
        } finally {
            $this->restrictedDiscoveryOnly = $previous;
        }
    }

    public function getEntityTypeManager(): EntityTypeManager
    {
        return $this->entityTypeManager;
    }

    public function getSchedule(): ?ScheduleInterface
    {
        return $this->schedule;
    }

    public function getDatabase(): DatabaseInterface
    {
        return $this->database;
    }

    /**
     * Return the exact active-purpose composition only after a complete runtime boot.
     *
     * Restricted discovery and failed or in-progress boots expose no partial graph.
     */
    public function getApplicationMasterRekeyComposition(): ?ApplicationMasterRekeyComposition
    {
        return $this->booted ? $this->applicationMasterRekeyComposition : null;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcher;
    }

    public function getMigrator(): Migrator
    {
        return $this->migrator;
    }

    public function getMigrationLoader(): MigrationLoader
    {
        return $this->migrationLoader;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function getManifest(): PackageManifest
    {
        return $this->manifest;
    }

    public function getAccessHandler(): EntityAccessHandler
    {
        return $this->accessHandler;
    }

    /**
     * @return list<ServiceProvider>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Build a PSR-11 container backed by the booted service providers.
     *
     * Resolution order:
     *   1. Each provider's resolve() — covers all framework services and
     *      explicitly bound abstracts (EntityTypeManager, DatabaseInterface, …).
     *   2. Reflection-based auto-wiring — instantiates concrete handler classes
     *      whose constructor parameters are resolvable from the same container.
     *
     * Used by ConsoleApplicationFactory and handler-backed Symfony commands to
     * resolve class-based handlers at dispatch time. Must be called after
     * bootForCli() / boot().
     */
    public function buildHandlerContainer(): \Psr\Container\ContainerInterface
    {
        $providers   = $this->providers;
        $projectRoot = $this->projectRoot;
        $diagnosticsConfig = $this->config['diagnostics'] ?? [];
        $cleanUrlProbeUrl = is_array($diagnosticsConfig)
            ? ($diagnosticsConfig['clean_url_probe_url'] ?? '')
            : '';
        $cleanUrlProbeUrl = is_string($cleanUrlProbeUrl) ? trim($cleanUrlProbeUrl) : '';

        // Explicit kernel-owned bindings: maps abstract id → factory closure.
        // These cover types that are not bound by any service provider but are
        // required by command handlers (e.g. HealthChecker needs BootDiagnosticReport).
        $kernel = $this;
        /** @var array<string, \Closure(\Psr\Container\ContainerInterface): object> $kernelBindings */
        $kernelBindings = [
            // Interface aliases for framework types that providers bind as concrete class keys.
            \Waaseyaa\Entity\EntityTypeManagerInterface::class =>
                static fn(\Psr\Container\ContainerInterface $c) => $c->get(\Waaseyaa\Entity\EntityTypeManager::class),

            // Role registry composed from every provider implementing
            // ProvidesRolesInterface, so role-aware handlers (e.g.
            // UserAssignRoleHandler) can stamp role permissions onto a user.
            \Waaseyaa\User\RoleRepository::class =>
                static fn(\Psr\Container\ContainerInterface $c) => \Waaseyaa\User\RoleRepository::fromProviders($providers),

            // Kernel-owned services not bound by any provider.
            \Waaseyaa\Access\Context\AccountContextInterface::class =>
                static fn(\Psr\Container\ContainerInterface $c) => $kernel->accountContext(),
            \Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport::class =>
                static fn(\Psr\Container\ContainerInterface $c) => $kernel->getBootReport(),
            \Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface::class =>
                static fn(\Psr\Container\ContainerInterface $c) => new \Waaseyaa\Foundation\Diagnostic\HealthChecker(
                    bootReport: $c->get(\Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport::class),
                    database: $c->get(\Waaseyaa\Database\DatabaseInterface::class),
                    entityTypeManager: $c->get(\Waaseyaa\Entity\EntityTypeManagerInterface::class),
                    projectRoot: $projectRoot,
                    logger: $c->get(\Waaseyaa\Foundation\Log\LoggerInterface::class),
                    cleanUrlProbe: $cleanUrlProbeUrl === ''
                        ? null
                        : new \Waaseyaa\Foundation\Diagnostic\CleanUrlProbe($cleanUrlProbeUrl),
                ),
        ];

        return new KernelHandlerContainer($providers, $kernelBindings);
    }

    /**
     * Return a snapshot of entity type registry status for operator diagnostics.
     *
     * Schema compatibility is derived from each type's field definitions when a
     * 'compatibility' key is present; otherwise defaults to 'liberal' (pre-v1 policy).
     */
    public function getBootReport(): \Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport
    {
        $definitions      = $this->entityTypeManager->getDefinitions();
        $disabledIds      = $this->lifecycleManager->getDisabledTypeIds();
        $schemaCompat     = [];

        foreach ($definitions as $id => $type) {
            $fieldDefs = $type->getFieldDefinitions();
            $schemaCompat[$id] = $fieldDefs['compatibility'] ?? 'liberal';
        }

        return new \Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport(
            registeredTypes: $definitions,
            disabledTypeIds: $disabledIds,
            schemaCompatibility: $schemaCompat,
        );
    }
}
