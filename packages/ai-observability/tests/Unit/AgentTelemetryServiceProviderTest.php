<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Observability\AgentTelemetryServiceProvider;
use Waaseyaa\AI\Observability\Event\AgentRunStarted;
use Waaseyaa\AI\Observability\Listener\AgentRunTelemetryListener;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * Production-mirroring wiring test (#1852 pattern): the kernel-services bus
 * serves the dispatcher ONLY under the Symfony-contracts FQCN
 * (ProviderRegistryKernelServices::get()). AgentTelemetryServiceProvider::boot()
 * previously resolved the foundation FQCN, which the bus never serves —
 * resolve() threw, the surrounding try/catch swallowed it, and the telemetry
 * listener silently never registered in a real kernel boot.
 */
#[CoversClass(AgentTelemetryServiceProvider::class)]
final class AgentTelemetryServiceProviderTest extends TestCase
{
    #[Test]
    public function boot_wires_telemetry_listener_to_agent_run_events(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $runRepository = new AgentRunRepository(
            $this->createStub(EntityRepositoryInterface::class),
            $this->createStub(DatabaseInterface::class),
        );

        $provider = new AgentTelemetryServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
            AgentRunRepository::class => $runRepository,
        ]));
        $provider->register();
        $provider->boot();

        $listeners = $dispatcher->getListeners(AgentRunStarted::class);
        $this->assertNotEmpty($listeners, 'Telemetry listener must subscribe to AgentRunStarted');
        $this->assertInstanceOf(AgentRunTelemetryListener::class, $listeners[0][0]);
    }

    #[Test]
    public function boot_without_dispatcher_is_a_no_op(): void
    {
        $provider = new AgentTelemetryServiceProvider();
        $provider->setKernelServices($this->kernelServices([]));
        $provider->register();

        $provider->boot();
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, object> $services
     */
    private function kernelServices(array $services): KernelServicesInterface
    {
        return new class ($services) implements KernelServicesInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $abstract): ?object
            {
                return $this->services[$abstract] ?? null;
            }
        };
    }
}
