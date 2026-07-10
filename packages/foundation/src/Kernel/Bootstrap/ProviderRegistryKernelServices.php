<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Event\EventDispatcherInterface as FoundationEventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
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

    /**
     * Memoized {@see GateInterface} adapter, rebuilt only when the resolved
     * access handler instance changes (G-014 / #1940). Constructing
     * {@see EntityAccessGate} is cheap, but callers resolving `GateInterface`
     * repeatedly within one request should see the same adapter instance.
     */
    private ?EntityAccessGate $gate = null;

    private ?EntityAccessHandler $gateHandler = null;

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
    ) {
        $this->providersAccessor = $providersAccessor;
        $this->accessHandlerAccessor = $accessHandlerAccessor;
    }

    public function get(string $abstract): ?object
    {
        if ($abstract === EntityTypeManager::class || $abstract === EntityTypeManagerInterface::class) {
            return $this->entityTypeManager;
        }
        if ($abstract === DatabaseInterface::class) {
            return $this->database;
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
        if ($abstract === PackageManifest::class) {
            return $this->manifest;
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
                $this->gate = new EntityAccessGate($handler, $this->logger);
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
