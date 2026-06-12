<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
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
     * @param \Closure(): list<ServiceProvider> $providersAccessor
     * @param AccountContextInterface|null $accountContext The kernel's shared acting-account
     *        context (mission revision-audit-provenance-01KTWY5V FR-002); null when the
     *        construction site has no kernel context.
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        \Closure $providersAccessor,
        private readonly ?AccountContextInterface $accountContext = null,
    ) {
        $this->providersAccessor = $providersAccessor;
    }

    public function get(string $abstract): ?object
    {
        if ($abstract === EntityTypeManager::class || $abstract === EntityTypeManagerInterface::class) {
            return $this->entityTypeManager;
        }
        if ($abstract === DatabaseInterface::class) {
            return $this->database;
        }
        if ($abstract === EventDispatcherInterface::class) {
            return $this->dispatcher;
        }
        if ($abstract === LoggerInterface::class) {
            return $this->logger;
        }
        if ($abstract === AccountContextInterface::class) {
            return $this->accountContext;
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
