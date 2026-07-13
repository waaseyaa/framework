<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Account\InitiatorAccountLoaderInterface;
use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\AiAgentServiceProvider;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Message\RunAgentHandler;
use Waaseyaa\AI\Agent\MessagingServiceProvider;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

final class ProductionDispatcherWiringTest extends TestCase
{
    #[Test]
    public function executorFactoryInjectsTheKernelDispatcher(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $provider = new AiAgentServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            ToolRegistryInterface::class => $this->createStub(ToolRegistryInterface::class),
            AgentRunRepository::class => $this->withoutConstructor(AgentRunRepository::class),
            AgentAuditLogRepository::class => $this->withoutConstructor(AgentAuditLogRepository::class),
            PackageManifest::class => new PackageManifest(),
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
        ]));
        $provider->register();

        $executor = $provider->resolve(AgentExecutor::class);

        self::assertSame($dispatcher, $this->property($executor, 'eventDispatcher'));
    }

    #[Test]
    public function handlerFactoryInjectsTheKernelDispatcher(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $provider = new MessagingServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            AgentRunRepository::class => $this->withoutConstructor(AgentRunRepository::class),
            AgentExecutor::class => $this->withoutConstructor(AgentExecutor::class),
            AgentDefinitionRegistry::class => $this->withoutConstructor(AgentDefinitionRegistry::class),
            ToolRegistryInterface::class => $this->createStub(ToolRegistryInterface::class),
            AgentRunBroadcasterInterface::class => $this->createStub(AgentRunBroadcasterInterface::class),
            ProviderInterface::class => $this->createStub(ProviderInterface::class),
            InitiatorAccountLoaderInterface::class => $this->createStub(InitiatorAccountLoaderInterface::class),
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $dispatcher,
        ]));
        $provider->register();

        $handler = $provider->resolve(RunAgentHandler::class);

        self::assertSame($dispatcher, $this->property($handler, 'eventDispatcher'));
    }

    /** @param array<class-string, object> $services */
    private function kernelServices(array $services): KernelServicesInterface
    {
        return new class ($services) implements KernelServicesInterface {
            /** @param array<class-string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $abstract): ?object
            {
                return $this->services[$abstract] ?? null;
            }
        };
    }

    private function withoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
