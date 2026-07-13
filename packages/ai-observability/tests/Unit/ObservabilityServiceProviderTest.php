<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Analysis\AnomalyDetector;
use Waaseyaa\AI\Observability\ObservabilityServiceProvider;
use Waaseyaa\AI\Observability\Recorder\NullTraceRecorder;
use Waaseyaa\AI\Observability\Recorder\TraceRecorderInterface;
use Waaseyaa\AI\Observability\TraceContext;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

#[CoversClass(ObservabilityServiceProvider::class)]
final class ObservabilityServiceProviderTest extends TestCase
{
    /** The R18 canonical AgentRun telemetry path supersedes this inert chain. */
    #[Test]
    public function legacyProducerlessTraceListenersAreNotRegistered(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();

        $provider = new ObservabilityServiceProvider();
        $provider->setKernelContext('', ['observability' => ['enabled' => false]], []);
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
        ]));
        $provider->register();
        $provider->boot();

        $llmListeners = $dispatcher->getListeners('Waaseyaa\\AI\\Agent\\Event\\LlmCallCompleted');
        self::assertSame([], $llmListeners);

        $toolListeners = $dispatcher->getListeners('Waaseyaa\\AI\\Agent\\Event\\ToolCallStarted');
        self::assertSame([], $toolListeners);
        self::assertFalse(class_exists('Waaseyaa\\AI\\Agent\\Event\\LlmCallCompleted'));
        self::assertFalse(class_exists('Waaseyaa\\AI\\Agent\\Event\\ToolCallStarted'));
        self::assertFalse(class_exists('Waaseyaa\\AI\\Agent\\Event\\ToolCallCompleted'));
        self::assertFalse(class_exists('Waaseyaa\\AI\\Observability\\Listener\\LlmCallListener'));
        self::assertFalse(class_exists('Waaseyaa\\AI\\Observability\\Listener\\ToolCallListener'));
    }

    #[Test]
    public function boot_without_dispatcher_is_a_no_op(): void
    {
        $provider = new ObservabilityServiceProvider();
        $provider->setKernelContext('', ['observability' => ['enabled' => false]], []);
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

    #[Test]
    public function disabledModeBindsNullTraceRecorder(): void
    {
        $provider = new ObservabilityServiceProvider();
        $provider->setKernelContext('', ['observability' => ['enabled' => false]], []);
        $provider->register();

        $recorder = $provider->resolve(TraceRecorderInterface::class);

        self::assertInstanceOf(NullTraceRecorder::class, $recorder);
    }

    #[Test]
    public function registersLocalBindingsWithoutKernel(): void
    {
        $provider = new ObservabilityServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->register();

        self::assertInstanceOf(TraceContext::class, $provider->resolve(TraceContext::class));
        self::assertInstanceOf(AnomalyDetector::class, $provider->resolve(AnomalyDetector::class));
    }

    #[Test]
    public function registersTraceEntityType(): void
    {
        $provider = new ObservabilityServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->register();

        $entityTypes = $provider->getEntityTypes();
        self::assertCount(1, $entityTypes);
        self::assertSame('trace', $entityTypes[0]->id());
    }
}
