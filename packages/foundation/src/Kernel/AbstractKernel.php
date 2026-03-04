<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

abstract class AbstractKernel
{
    protected EventDispatcherInterface $dispatcher;
    protected PdoDatabase $database;
    protected EntityTypeManager $entityTypeManager;
    protected PackageManifest $manifest;
    protected EntityAccessHandler $accessHandler;

    /** @var array<string, mixed> */
    protected array $config = [];

    /** @var list<ServiceProvider> */
    protected array $providers = [];

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

        $this->config = ConfigLoader::load($this->projectRoot . '/config/waaseyaa.php');

        $this->dispatcher = new EventDispatcher();
        $this->bootDatabase();
        $this->bootEntityTypeManager();
        $this->compileManifest();
        $this->discoverAndRegisterProviders();
        $this->loadAppEntityTypes();
        $this->bootProviders();
        $this->discoverAccessPolicies();

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

            $provider->setKernelContext($this->projectRoot, $this->config);

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
}
