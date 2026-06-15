<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Regression test for audit finding D-31.
 *
 * The hand-rolled PSR-11 container built by {@see AbstractKernel::buildHandlerContainer()}
 * must NOT swallow real construction failures from a provider's resolve().
 * It may only fall through to the next provider when the id is genuinely
 * *unbound* (the canonical "No binding registered for …" signal). Any other
 * failure must propagate so the true cause is not masked as a misleading
 * "No binding for … in KernelHandlerContainer" NotFoundException.
 */
#[CoversClass(AbstractKernel::class)]
final class HandlerContainerResolveErrorTest extends TestCase
{
    #[Test]
    public function real_construction_error_from_provider_propagates(): void
    {
        $provider = $this->providerThatFailsToConstruct('boom.service');
        $kernel   = $this->kernelWithProviders([$provider]);

        $container = $kernel->buildHandlerContainer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('construction blew up');

        $container->get('boom.service');
    }

    #[Test]
    public function genuinely_unbound_id_still_falls_through_to_not_found(): void
    {
        // A provider that only ever signals "unbound" must not short-circuit;
        // the container should reach its own NotFoundException for an id that
        // is neither bound nor an existing class.
        $provider = $this->providerThatFailsToConstruct('boom.service');
        $kernel   = $this->kernelWithProviders([$provider]);

        $container = $kernel->buildHandlerContainer();

        $this->expectException(NotFoundExceptionInterface::class);

        $container->get('totally.unbound.id');
    }

    /**
     * @param list<\Waaseyaa\Foundation\ServiceProvider\ServiceProviderInterface> $providers
     */
    private function kernelWithProviders(array $providers): AbstractKernel
    {
        $kernel = new class('/tmp/waaseyaa-d31-test') extends AbstractKernel {
            /** @param list<\Waaseyaa\Foundation\ServiceProvider\ServiceProviderInterface> $providers */
            public function withProviders(array $providers): void
            {
                $this->providers = $providers;
            }
        };
        $kernel->withProviders($providers);

        return $kernel;
    }

    /**
     * A provider whose resolve() throws a real construction error for one id
     * and the canonical "unbound" RuntimeException for everything else —
     * mirroring {@see ServiceProvider::resolve()} exactly.
     */
    private function providerThatFailsToConstruct(string $failingId): ServiceProvider
    {
        return new class($failingId) extends ServiceProvider {
            public function __construct(private readonly string $failingId) {}

            public function register(): void {}

            public function resolve(string $abstract): object
            {
                if ($abstract === $this->failingId) {
                    // A real construction failure (not an "unbound" signal).
                    throw new \LogicException('construction blew up');
                }

                throw new \RuntimeException("No binding registered for {$abstract}.");
            }
        };
    }
}
