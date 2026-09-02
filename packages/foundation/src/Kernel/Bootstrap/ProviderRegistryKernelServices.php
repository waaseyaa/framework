<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Database\DatabaseIdentityProviderInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Foundation\Community\CommunityContextInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Event\EventDispatcherInterface as FoundationEventDispatcherInterface;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProviderCapabilitySource;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Default {@see KernelServicesInterface} implementation backed by the kernel's
 * core services and the live provider list maintained by {@see ProviderRegistry}.
 *
 * The provider list is read through a closure accessor so resolution sees the
 * current registration state at call time — important when a provider's
 * `register()` resolves a service bound by a sibling registered earlier in
 * the same pass.
 */
final class ProviderRegistryKernelServices implements KernelServicesInterface
{
    /** @var \Closure(): list<ServiceProvider> */
    private \Closure $providersAccessor;

    /**
     * Lazy accessor for the kernel's per-entity access handler. Resolved at
     * call time (not construction) because the handler is built by
     * {@see \Waaseyaa\Foundation\Kernel\AbstractKernel::discoverAccessPolicies()}
     * AFTER providers register and obtain this bus. Null when no kernel context
     * exposes a handler (e.g. unit construction sites).
     *
     * @var (\Closure(): ?EntityAccessHandler)|null
     */
    private readonly ?\Closure $accessHandlerAccessor;

    /** @var (\Closure(): HealthCheckerInterface)|null */
    private readonly ?\Closure $healthCheckerAccessor;

    /**
     * Memoized {@see GateInterface} adapter, rebuilt only when the resolved
     * access handler instance changes (G-014 / #1940). Constructing
     * {@see EntityAccessGate} is cheap, but callers resolving `GateInterface`
     * repeatedly within one request should see the same adapter instance.
     */
    private ?EntityAccessGate $gate = null;

    private ?EntityAccessHandler $gateHandler = null;

    private ?ProviderCapabilitySource $providerCapabilities = null;

    /**
     * @param \Closure(): list<ServiceProvider> $providersAccessor
     * @param AccountContextInterface|null $accountContext The kernel's shared acting-account
     *        context (mission revision-audit-provenance-01KTWY5V FR-002); null when the
     *        construction site has no kernel context.
     * @param (\Closure(): ?EntityAccessHandler)|null $accessHandlerAccessor Lazy
     *        accessor for the kernel access handler (C-12). Null leaves
     *        {@see EntityAccessHandler::class} unresolvable through this bus.
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        \Closure $providersAccessor,
        private readonly ?AccountContextInterface $accountContext = null,
        ?\Closure $accessHandlerAccessor = null,
        private readonly ?PackageManifest $manifest = null,
        private readonly ?ApplicationSecret $applicationSecret = null,
        private readonly ?AccountFieldReadScopeInterface $fieldReadScope = null,
        /**
         * The live request's {@see RequestContext} (#2167).
         *
         * Null on every construction site without an HTTP request — CLI,
         * console, unit tests — where consumers keep the anonymous default
         * their own provider binds.
         */
        private readonly ?RequestContext $requestContext = null,
        private readonly ?CommunityContextInterface $communityContext = null,
        private readonly ?SecretResolverRegistry $secretResolverRegistry = null,
        /**
         * Lazy accessor for the kernel-owned {@see HealthCheckerInterface}
         * (#2820). The checker is composed from the kernel's boot diagnostic
         * report, so only the kernel can build it — before this accessor a
         * provider binding that depended on it (cli's `HealthReportHandler`)
         * could only resolve through the handler container's kernel bindings,
         * never through this bus, and fell through to reflection auto-wiring
         * in every consumer application. Lazy because the report reflects
         * the entity types registered by the time it is read. Null leaves
         * `HealthCheckerInterface::class` unresolvable through this bus.
         *
         * @var (\Closure(): HealthCheckerInterface)|null
         */
        ?\Closure $healthCheckerAccessor = null,
    ) {
        $this->providersAccessor = $providersAccessor;
        $this->accessHandlerAccessor = $accessHandlerAccessor;
        $this->healthCheckerAccessor = $healthCheckerAccessor;
    }

    public function get(string $abstract): ?object
    {
        if ($abstract === HealthCheckerInterface::class) {
            return $this->healthCheckerAccessor !== null
                ? ($this->healthCheckerAccessor)()
                : null;
        }
        if ($abstract === ProviderCapabilitySource::class) {
            return $this->providerCapabilities ??= new ProviderCapabilitySource($this->providersAccessor);
        }
        if ($abstract === RequestContext::class) {
            // #2167: per-request state. The listing ServiceProvider binds an
            // anonymous default so it works without a kernel; when a real
            // request exists the kernel supplies this one instead, which is
            // the only way `?page=` (and exposed filter params) reach
            // ListingResolver. Null here means "no request", not "no value" —
            // the provider's default stands.
            return $this->requestContext;
        }
        if ($abstract === CommunityContextInterface::class) {
            return $this->communityContext;
        }
        if ($abstract === EntityTypeManager::class || $abstract === EntityTypeManagerInterface::class) {
            return $this->entityTypeManager;
        }
        if ($abstract === FieldDefinitionRegistryInterface::class) {
            // #2047: this hardcoded kernel-owned case intentionally precedes
            // and shadows sibling-provider bindings (including FieldServiceProvider's
            // duplicate registry). Admin and API consumers must see the exact
            // canonical registry already held by EntityTypeManager, never a
            // separately constructed registry.
            try {
                return $this->entityTypeManager->getFieldRegistry();
            } catch (\RuntimeException) {
                // Bare/unit-constructed managers may omit the registry. Preserve
                // optional bus semantics instead of leaking the manager exception.
                return null;
            }
        }
        if ($abstract === FieldTypeManagerInterface::class || $abstract === FieldTypeManager::class) {
            // #2786 B1: the boot-scoped field-type registry is exactly the one
            // the kernel's canonical field registry admits with, so providers
            // resolving it see every downstream plugin the manifest admitted.
            // Like FieldDefinitionRegistryInterface above, this kernel-owned
            // case precedes sibling-provider bindings; a bare/unit-constructed
            // manager without that registry resolves null.
            try {
                $registry = $this->entityTypeManager->getFieldRegistry();
            } catch (\RuntimeException) {
                return null;
            }
            if (!$registry instanceof FieldDefinitionRegistry) {
                return null;
            }
            $fieldTypes = $registry->fieldTypeManager();
            if ($abstract === FieldTypeManager::class && !$fieldTypes instanceof FieldTypeManager) {
                return null;
            }

            return $fieldTypes;
        }
        if ($abstract === DatabaseInterface::class) {
            return $this->database;
        }
        if ($abstract === DatabaseIdentityProviderInterface::class) {
            return $this->database instanceof DatabaseIdentityProviderInterface
                ? $this->database
                : null;
        }
        if ($abstract === EventDispatcherInterface::class || $abstract === PsrEventDispatcherInterface::class) {
            // Symfony\Contracts\EventDispatcher\EventDispatcherInterface (the
            // property's declared type) extends the PSR-14 contract, so this
            // branch is statically guaranteed for both FQCNs.
            return $this->dispatcher;
        }
        if ($abstract === FoundationEventDispatcherInterface::class) {
            // G-025 (#1940): the property type (Symfony contracts) does not
            // statically guarantee the Waaseyaa-owned contract, but every
            // kernel binds SymfonyEventDispatcherAdapter, which implements
            // both. Guard with instanceof rather than assuming.
            return $this->dispatcher instanceof FoundationEventDispatcherInterface
                ? $this->dispatcher
                : null;
        }
        if ($abstract === LoggerInterface::class) {
            return $this->logger;
        }
        if ($abstract === AccountContextInterface::class) {
            return $this->accountContext;
        }
        if ($abstract === AccountFieldReadScopeInterface::class && $this->fieldReadScope !== null) {
            return $this->fieldReadScope;
        }
        if ($abstract === PackageManifest::class) {
            return $this->manifest;
        }
        if ($abstract === ApplicationSecret::class) {
            return $this->applicationSecret;
        }
        if ($abstract === SecretResolverRegistry::class) {
            return $this->secretResolverRegistry;
        }
        if ($abstract === EntityAccessHandler::class) {
            return $this->accessHandlerAccessor !== null
                ? ($this->accessHandlerAccessor)()
                : null;
        }
        if ($abstract === GateInterface::class) {
            $handler = $this->accessHandlerAccessor !== null
                ? ($this->accessHandlerAccessor)()
                : null;
            if ($handler === null) {
                return null;
            }
            if ($this->gate === null || $this->gateHandler !== $handler) {
                $this->gate = new EntityAccessGate($handler, $this->logger, $this->fieldReadScope, $this->accountContext);
                $this->gateHandler = $handler;
            }
            return $this->gate;
        }
        if ($abstract === \PDO::class) {
            assert($this->database instanceof DBALDatabase);
            $pdo = $this->database->getConnection()->getNativeConnection();
            assert($pdo instanceof \PDO);
            return $pdo;
        }

        foreach (($this->providersAccessor)() as $other) {
            if (isset($other->getBindings()[$abstract])) {
                return $other->resolve($abstract);
            }
        }

        return null;
    }
}
