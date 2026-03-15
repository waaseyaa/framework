<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\PdoDatabase;
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
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Plugin\Attribute\WaaseyaaPlugin;
use Waaseyaa\Plugin\DefaultPluginManager;
use Waaseyaa\Plugin\Discovery\AttributeDiscovery;
use Waaseyaa\Plugin\Extension\KnowledgeToolingExtensionRunner;

abstract class AbstractKernel
{
    protected EventDispatcherInterface $dispatcher;
    protected PdoDatabase $database;
    protected EntityTypeManager $entityTypeManager;
    protected PackageManifest $manifest;
    protected EntityAccessHandler $accessHandler;
    protected EntityTypeLifecycleManager $lifecycleManager;
    protected EntityAuditLogger $entityAuditLogger;

    /** @var array<string, mixed> */
    protected array $config = [];

    /** @var list<ServiceProvider> */
    protected array $providers = [];

    private ?KnowledgeToolingExtensionRunner $knowledgeExtensionRunner = null;
    private bool $booted = false;

    public function __construct(
        protected readonly string $projectRoot,
    ) {}

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
        $dbPath = $this->config['database'] ?? null;
        if ($dbPath === null) {
            $dbPath = getenv('WAASEYAA_DB') ?: $this->projectRoot . '/waaseyaa.sqlite';
        }

        $this->database = PdoDatabase::createSqlite($dbPath);
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
        $compiler = new PackageManifestCompiler(
            basePath: $this->projectRoot,
            storagePath: $this->projectRoot . '/storage',
        );
        $this->manifest = $compiler->load();
    }

    protected function discoverAndRegisterProviders(): void
    {
        // Instantiate providers from manifest
        foreach ($this->manifest->providers as $providerClass) {
            if (!class_exists($providerClass)) {
                error_log(sprintf('[Waaseyaa] Provider class not found: %s', $providerClass));
                continue;
            }

            $provider = new $providerClass();
            if (!$provider instanceof ServiceProvider) {
                error_log(sprintf('[Waaseyaa] Class %s is not a ServiceProvider', $providerClass));
                continue;
            }

            $provider->setKernelContext($this->projectRoot, $this->config, $this->manifest->formatters);

            $this->providers[] = $provider;
        }

        // Register all providers, then collect their entity types.
        foreach ($this->providers as $provider) {
            $provider->register();
        }

        // Register entity types declared by providers.
        foreach ($this->providers as $provider) {
            foreach ($provider->getEntityTypes() as $entityType) {
                try {
                    $this->entityTypeManager->registerEntityType($entityType);
                } catch (\RuntimeException | \InvalidArgumentException $e) {
                    error_log(sprintf(
                        '[Waaseyaa] Failed to register entity type "%s" from %s: %s',
                        $entityType->id(),
                        $provider::class,
                        $e->getMessage(),
                    ));
                }
            }
        }
    }

    protected function loadAppEntityTypes(): void
    {
        $path = $this->projectRoot . '/config/entity-types.php';
        $types = ConfigLoader::load($path);

        foreach ($types as $index => $typeData) {
            if (!$typeData instanceof \Waaseyaa\Entity\EntityTypeInterface) {
                error_log(sprintf(
                    '[Waaseyaa] config/entity-types.php item at index %s is not an EntityTypeInterface (got %s).',
                    $index,
                    get_debug_type($typeData),
                ));
                continue;
            }

            try {
                $this->entityTypeManager->registerEntityType($typeData);
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                error_log(sprintf(
                    '[Waaseyaa] Failed to register app entity type "%s": %s',
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
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
    }

    protected function discoverAccessPolicies(): void
    {
        $policies = [];
        foreach ($this->manifest->policies as $class => $entityTypes) {
            if (!class_exists($class)) {
                error_log(sprintf(
                    '[Waaseyaa] Access policy class not found: %s (covering entity types: %s). '
                    . 'Run "composer dump-autoload --optimize" to update the classmap.',
                    $class,
                    implode(', ', $entityTypes),
                ));
                continue;
            }

            try {
                // Policies may accept their entity type list as a constructor
                // argument (e.g. ConfigEntityAccessPolicy). Detect via reflection.
                $ref = new \ReflectionClass($class);
                $constructor = $ref->getConstructor();
                if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                    $policies[] = new $class($entityTypes);
                } else {
                    $policies[] = new $class();
                }
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[Waaseyaa] Failed to instantiate access policy %s: %s',
                    $class,
                    $e->getMessage(),
                ));
            }
        }

        $this->accessHandler = new EntityAccessHandler($policies);
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
            error_log(sprintf('[Waaseyaa] Failed to boot knowledge extension runner: %s', $e->getMessage()));
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

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public function getEntityTypeManager(): EntityTypeManager
    {
        return $this->entityTypeManager;
    }

    public function getDatabase(): PdoDatabase
    {
        return $this->database;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcher;
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
