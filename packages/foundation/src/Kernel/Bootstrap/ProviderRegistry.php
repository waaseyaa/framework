<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class ProviderRegistry
{
    /** @var list<ServiceProvider> */
    private array $providers = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Discover, instantiate, and register all service providers from the manifest.
     *
     * @param array<string, mixed> $config
     * @return list<ServiceProvider>
     */
    public function discoverAndRegister(
        PackageManifest $manifest,
        string $projectRoot,
        array $config,
        EntityTypeManager $entityTypeManager,
        DatabaseInterface $database,
        EventDispatcherInterface $dispatcher,
    ): array {
        $this->providers = [];

        foreach ($manifest->providers as $providerClass) {
            if (!class_exists($providerClass)) {
                $this->logger->warning(sprintf('Provider class not found: %s', $providerClass));
                continue;
            }

            $provider = new $providerClass();
            if (!$provider instanceof ServiceProvider) {
                $this->logger->warning(sprintf('Class %s is not a ServiceProvider', $providerClass));
                continue;
            }

            $provider->setKernelContext($projectRoot, $config, $manifest->formatters);
            $provider->setKernelResolver(function (string $className) use ($entityTypeManager, $database, $dispatcher): ?object {
                if ($className === \Waaseyaa\Entity\EntityTypeManager::class) {
                    return $entityTypeManager;
                }
                if ($className === \Waaseyaa\Database\DatabaseInterface::class) {
                    return $database;
                }
                if ($className === \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class) {
                    return $dispatcher;
                }
                if ($className === \Waaseyaa\Foundation\Log\LoggerInterface::class) {
                    return $this->logger;
                }
                if ($className === \PDO::class) {
                    assert($database instanceof \Waaseyaa\Database\DBALDatabase);
                    $pdo = $database->getConnection()->getNativeConnection();
                    assert($pdo instanceof \PDO);
                    return $pdo;
                }
                foreach ($this->providers as $other) {
                    if (isset($other->getBindings()[$className])) {
                        return $other->resolve($className);
                    }
                }

                return null;
            });

            $this->providers[] = $provider;
        }

        foreach ($this->providers as $provider) {
            $provider->register();
        }

        foreach ($this->providers as $provider) {
            foreach ($provider->getEntityTypes() as $entityType) {
                try {
                    $entityTypeManager->registerEntityType($entityType);
                } catch (\RuntimeException | \InvalidArgumentException $e) {
                    $this->logger->error(sprintf(
                        'Failed to register entity type "%s" from %s: %s',
                        $entityType->id(),
                        $provider::class,
                        $e->getMessage(),
                    ));
                }
            }
        }

        return $this->providers;
    }

    /**
     * Boot all registered providers.
     *
     * @param list<ServiceProvider> $providers
     */
    public function boot(array $providers): void
    {
        foreach ($providers as $provider) {
            $provider->boot();
        }
    }
}
