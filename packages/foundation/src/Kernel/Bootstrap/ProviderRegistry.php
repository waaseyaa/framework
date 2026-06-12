<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Exception\EntityTypeRegistrationCollisionException;
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
     * @param AccountContextInterface|null $accountContext The kernel's shared acting-account
     *        context, exposed to providers via the kernel-services bus (mission
     *        revision-audit-provenance-01KTWY5V FR-002).
     * @return list<ServiceProvider>
     */
    public function discoverAndRegister(
        PackageManifest $manifest,
        string $projectRoot,
        array $config,
        EntityTypeManager $entityTypeManager,
        DatabaseInterface $database,
        EventDispatcherInterface $dispatcher,
        ?AccountContextInterface $accountContext = null,
    ): array {
        $this->providers = [];

        $kernelServices = new ProviderRegistryKernelServices(
            entityTypeManager: $entityTypeManager,
            database: $database,
            dispatcher: $dispatcher,
            logger: $this->logger,
            providersAccessor: fn(): array => $this->providers,
            accountContext: $accountContext,
        );

        foreach ($manifest->providers as $providerClass) {
            if (!class_exists($providerClass)) {
                $this->logger->warning(sprintf(
                    'Provider class not found: %s. '
                    . 'Fix the declaration in composer.json or run: php bin/waaseyaa optimize:manifest',
                    $providerClass,
                ));
                continue;
            }

            $provider = new $providerClass();
            if (!$provider instanceof ServiceProvider) {
                $this->logger->warning(sprintf('Class %s is not a ServiceProvider', $providerClass));
                continue;
            }

            $provider->setKernelContext($projectRoot, $config, $manifest->formatters);
            $provider->setKernelServices($kernelServices);

            $this->providers[] = $provider;
        }

        foreach ($this->providers as $provider) {
            $provider->register();
        }

        foreach ($this->providers as $provider) {
            foreach ($provider->getEntityTypeRegistrations() as $registration) {
                $entityType = $registration['entityType'];
                try {
                    $entityTypeManager->registerEntityType($entityType, $registration['registrant']);
                } catch (EntityTypeRegistrationCollisionException $e) {
                    $this->logger->error(sprintf(
                        'Failed to register entity type "%s" from %s: %s',
                        $entityType->id(),
                        $provider::class,
                        $e->getMessage(),
                    ));

                    throw $e;
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error(sprintf(
                        'Failed to register entity type "%s" from %s: %s',
                        $entityType->id(),
                        $provider::class,
                        $e->getMessage(),
                    ));
                }
            }
        }

        $autoRegister = filter_var($config['entity_auto_register'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($autoRegister && $manifest->attributeEntityTypes !== [] && interface_exists(\Waaseyaa\Entity\DefinesEntityType::class)) {
            foreach ($manifest->attributeEntityTypes as $entityClass) {
                if (!class_exists($entityClass)) {
                    continue;
                }
                if (!is_subclass_of($entityClass, \Waaseyaa\Entity\DefinesEntityType::class, true)) {
                    continue;
                }
                $entityType = $entityClass::entityType();
                if ($entityTypeManager->hasDefinition($entityType->id())) {
                    continue;
                }
                try {
                    $entityTypeManager->registerEntityType($entityType, $entityClass);
                } catch (EntityTypeRegistrationCollisionException $e) {
                    $this->logger->error(sprintf(
                        'Failed to auto-register entity type "%s" from %s: %s',
                        $entityType->id(),
                        $entityClass,
                        $e->getMessage(),
                    ));

                    throw $e;
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error(sprintf(
                        'Failed to auto-register entity type "%s" from %s: %s',
                        $entityType->id(),
                        $entityClass,
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
