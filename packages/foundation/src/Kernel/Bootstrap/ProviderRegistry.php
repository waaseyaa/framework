<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Exception\EntityTypeRegistrationCollisionException;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
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
     * @param (\Closure(): ?EntityAccessHandler)|null $accessHandlerAccessor Lazy
     *        accessor for the kernel access handler (C-12), exposed to providers
     *        via the kernel-services bus. Lazy because access policies are
     *        discovered after this registration pass.
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
        ?\Closure $accessHandlerAccessor = null,
        ?ApplicationSecret $applicationSecret = null,
        ?AccountFieldReadScopeInterface $fieldReadScope = null,
    ): array {
        $this->providers = [];

        $kernelServices = new ProviderRegistryKernelServices(
            entityTypeManager: $entityTypeManager,
            database: $database,
            dispatcher: $dispatcher,
            logger: $this->logger,
            providersAccessor: fn(): array => $this->providers,
            accountContext: $accountContext,
            accessHandlerAccessor: $accessHandlerAccessor,
            manifest: $manifest,
            applicationSecret: $applicationSecret,
            fieldReadScope: $fieldReadScope,
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
        if ($autoRegister && $manifest->attributeEntityTypes !== []) {
            foreach ($manifest->attributeEntityTypes as $entityClass) {
                if (!class_exists($entityClass)) {
                    continue;
                }
                $usesCanonicalMetadata = is_subclass_of($entityClass, \Waaseyaa\Entity\ContentEntityBase::class, true)
                    && new \ReflectionClass($entityClass)->getAttributes(\Waaseyaa\Entity\Attribute\ContentEntityType::class) !== [];
                if ($usesCanonicalMetadata) {
                    $entityType = \Waaseyaa\Entity\EntityType::fromClass($entityClass);
                } elseif (interface_exists(\Waaseyaa\Entity\DefinesEntityType::class)
                    && is_subclass_of($entityClass, \Waaseyaa\Entity\DefinesEntityType::class, true)
                ) {
                    $entityType = $entityClass::entityType();
                } elseif (is_subclass_of($entityClass, \Waaseyaa\Entity\ContentEntityBase::class, true)) {
                    $entityType = \Waaseyaa\Entity\EntityType::fromClass($entityClass);
                } else {
                    continue;
                }
                if ($entityTypeManager->hasDefinition($entityType->id())) {
                    $registered = $entityTypeManager->getDefinition($entityType->id());
                    if ($registered->getClass() === $entityType->getClass()) {
                        continue;
                    }
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
