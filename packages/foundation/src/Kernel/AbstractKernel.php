<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\Audit\EntityWriteAuditListener;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Diagnostic\DiagnosticCode;
use Waaseyaa\Foundation\Diagnostic\DiagnosticEmitter;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;
use Waaseyaa\Foundation\Kernel\Bootstrap\ManifestBootstrapper;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Log\Handler\ErrorLogHandler as HandlerErrorLogHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogManager;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Plugin\Attribute\WaaseyaaPlugin;
use Waaseyaa\Plugin\DefaultPluginManager;
use Waaseyaa\Plugin\Discovery\AttributeDiscovery;
use Waaseyaa\Plugin\Extension\KnowledgeToolingExtensionRunner;

abstract class AbstractKernel
{
    protected EventDispatcherInterface $dispatcher;
    protected DatabaseInterface $database;
    protected EntityTypeManager $entityTypeManager;
    protected PackageManifest $manifest;
    protected EntityAccessHandler $accessHandler;
    protected EntityTypeLifecycleManager $lifecycleManager;
    protected EntityAuditLogger $entityAuditLogger;
    protected Migrator $migrator;
    protected MigrationLoader $migrationLoader;

    /** @var array<string, mixed> */
    protected array $config = [];

    /** @var list<ServiceProvider> */
    protected array $providers = [];

    private ?KnowledgeToolingExtensionRunner $knowledgeExtensionRunner = null;
    private bool $booted = false;
    protected LoggerInterface $logger;

    public function __construct(
        protected readonly string $projectRoot,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new LogManager(
            new HandlerErrorLogHandler(),
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

        $this->config = ConfigLoader::load($this->projectRoot . '/config/waaseyaa.php');

        // Upgrade logger from config.
        if ($this->logger instanceof LogManager) {
            $loggingConfig = $this->config['logging'] ?? [];
            if (is_array($loggingConfig) && isset($loggingConfig['channels'])) {
                $this->logger = LogManager::fromConfig($loggingConfig);
            } else {
                $level = LogLevel::fromName((string) ($this->config['log_level'] ?? 'warning')) ?? LogLevel::WARNING;
                $this->logger = new LogManager(new HandlerErrorLogHandler(minimumLevel: $level));
            }
        }

        // Safety guard: refuse to boot with debug enabled in production.
        if ($this->isDebugMode() && !$this->isDevelopmentMode()) {
            throw new \RuntimeException(
                sprintf('APP_DEBUG must not be enabled in production (APP_ENV=%s). Aborting boot.', $this->resolveEnvironment()),
            );
        }

        $this->dispatcher         = new EventDispatcher();
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
        $this->loadAppEntityTypes();
        $this->validateContentTypes();
        $this->bootProviders();
        $this->discoverAccessPolicies();
        $this->bootKnowledgeExtensionRunner();

        $this->booted = true;
    }

    protected function bootDatabase(): void
    {
        $this->database = (new DatabaseBootstrapper())->boot($this->projectRoot, $this->config);
    }

    protected function bootEntityTypeManager(): void
    {
        $database = $this->database;
        $dispatcher = $this->dispatcher;

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function (EntityTypeInterface $definition) use ($database, $dispatcher): SqlEntityStorage {
                $schemaHandler = new SqlSchemaHandler($definition, $database);
                $schemaHandler->ensureTable();
                return new SqlEntityStorage($definition, $database, $dispatcher);
            },
        );
    }

    protected function compileManifest(): void
    {
        $this->manifest = (new ManifestBootstrapper())->boot($this->projectRoot);
    }

    protected function bootMigrations(): void
    {
        // Reuse the DBAL connection from bootDatabase() instead of creating a second one.
        assert($this->database instanceof DBALDatabase);
        $connection = $this->database->getConnection();

        $repository = new MigrationRepository($connection);
        $repository->createTable();

        $this->migrationLoader = new MigrationLoader($this->projectRoot, $this->manifest);
        $this->migrator = new Migrator($connection, $repository);
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
        );
    }

    protected function loadAppEntityTypes(): void
    {
        $path = $this->projectRoot . '/config/entity-types.php';
        $types = ConfigLoader::load($path);

        foreach ($types as $index => $typeData) {
            if (!$typeData instanceof \Waaseyaa\Entity\EntityTypeInterface) {
                $this->logger->warning(sprintf(
                    'config/entity-types.php item at index %s is not an EntityTypeInterface (got %s).',
                    $index,
                    get_debug_type($typeData),
                ));
                continue;
            }

            try {
                $this->entityTypeManager->registerEntityType($typeData);
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                $this->logger->error(sprintf(
                    'Failed to register app entity type "%s": %s',
                    $typeData->id(),
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * Validate that at least one content type is registered and enabled.
     *
     * Throws DEFAULT_TYPE_MISSING if no types are registered at all.
     * Throws DEFAULT_TYPE_DISABLED if all registered types have been disabled
     * via the lifecycle manager.
     *
     * @throws \RuntimeException
     */
    protected function validateContentTypes(): void
    {
        $emitter     = new DiagnosticEmitter();
        $definitions = $this->entityTypeManager->getDefinitions();

        if ($definitions === []) {
            $entry = $emitter->emit(
                DiagnosticCode::DEFAULT_TYPE_MISSING,
                DiagnosticCode::DEFAULT_TYPE_MISSING->defaultMessage(),
                ['registered_type_count' => 0],
            );
            throw new \RuntimeException('[CRITICAL] ' . $entry->code->value . ': ' . $entry->message);
        }

        $disabledIds  = $this->lifecycleManager->getDisabledTypeIds();
        $enabledTypes = array_filter(
            $definitions,
            static fn(\Waaseyaa\Entity\EntityTypeInterface $def): bool => !in_array($def->id(), $disabledIds, true),
        );

        if ($enabledTypes === []) {
            $entry = $emitter->emit(
                DiagnosticCode::DEFAULT_TYPE_DISABLED,
                DiagnosticCode::DEFAULT_TYPE_DISABLED->defaultMessage(),
                ['disabled_ids' => $disabledIds, 'registered_type_count' => count($definitions)],
            );
            throw new \RuntimeException('[CRITICAL] ' . $entry->code->value . ': ' . $entry->message);
        }
    }

    protected function bootProviders(): void
    {
        (new ProviderRegistry($this->logger))->boot($this->providers);
    }

    protected function discoverAccessPolicies(): void
    {
        $this->accessHandler = (new AccessPolicyRegistry($this->logger))->discover($this->manifest);
    }

    protected function bootKnowledgeExtensionRunner(): void
    {
        $config = is_array($this->config['extensions'] ?? null) ? $this->config['extensions'] : [];
        $rawDirectories = $config['plugin_directories'] ?? [];
        if (is_string($rawDirectories)) {
            $rawDirectories = [$rawDirectories];
        }
        if (!is_array($rawDirectories)) {
            $rawDirectories = [];
        }

        $directories = [];
        foreach ($rawDirectories as $directory) {
            if (!is_string($directory)) {
                continue;
            }
            $trimmed = trim($directory);
            if ($trimmed === '') {
                continue;
            }
            if (!str_starts_with($trimmed, '/')) {
                $trimmed = $this->projectRoot . '/' . ltrim($trimmed, '/');
            }
            $directories[] = $trimmed;
        }
        $directories = array_values(array_unique($directories));
        sort($directories);

        if ($directories === []) {
            $this->knowledgeExtensionRunner = new KnowledgeToolingExtensionRunner([]);
            return;
        }

        $attributeClass = is_string($config['plugin_attribute'] ?? null)
            ? trim((string) $config['plugin_attribute'])
            : WaaseyaaPlugin::class;
        if ($attributeClass === '') {
            $attributeClass = WaaseyaaPlugin::class;
        }

        try {
            $discovery = new AttributeDiscovery(
                directories: $directories,
                attributeClass: $attributeClass,
            );
            $manager = new DefaultPluginManager($discovery);
            $this->knowledgeExtensionRunner = KnowledgeToolingExtensionRunner::fromPluginManager($manager);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Failed to boot knowledge extension runner: %s', $e->getMessage()));
            $this->knowledgeExtensionRunner = new KnowledgeToolingExtensionRunner([]);
        }
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
        return in_array(strtolower($this->resolveEnvironment()), ['dev', 'development', 'local'], true);
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

    public function getEntityTypeManager(): EntityTypeManager
    {
        return $this->entityTypeManager;
    }

    public function getDatabase(): DatabaseInterface
    {
        return $this->database;
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
