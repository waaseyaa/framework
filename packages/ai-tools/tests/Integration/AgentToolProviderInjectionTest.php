<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\ProvidesAgentToolsInterface;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsAgentToolProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

#[CoversClass(AbstractKernel::class)]
final class AgentToolProviderInjectionTest extends TestCase
{
    #[Test]
    public function kernel_injects_only_tool_contributors_in_class_name_order(): void
    {
        $receiver = new CapturingToolReceiver();
        $kernel = new class('/tmp/waaseyaa-agent-tool-provider-test') extends AbstractKernel {
            /** @param list<ServiceProvider> $providers */
            public function inject(array $providers): void
            {
                $this->providers = $providers;
                $this->injectAgentToolProviders();
            }
        };

        $kernel->inject([
            new ZToolContributor(),
            new NonToolProvider(),
            $receiver,
            new AToolContributor(),
        ]);

        self::assertSame(
            [AToolContributor::class, ZToolContributor::class],
            array_map(static fn(object $provider): string => $provider::class, $receiver->providers),
        );
    }
}

final class CapturingToolReceiver extends ServiceProvider implements AcceptsAgentToolProvidersInterface
{
    /** @var list<object> */
    public array $providers = [];

    public function register(): void {}

    public function withAgentToolProviders(array $providers): void
    {
        $this->providers = $providers;
    }
}

final class AToolContributor extends ServiceProvider implements ProvidesAgentToolsInterface
{
    public function register(): void {}
    public function registerAgentTools(ToolRegistryInterface $registry): void {}
}

final class ZToolContributor extends ServiceProvider implements ProvidesAgentToolsInterface
{
    public function register(): void {}
    public function registerAgentTools(ToolRegistryInterface $registry): void {}
}

final class NonToolProvider extends ServiceProvider
{
    public function register(): void {}
}
