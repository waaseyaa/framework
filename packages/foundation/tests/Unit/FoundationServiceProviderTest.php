<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Community\CommunityContext;
use Waaseyaa\Foundation\Community\CommunityContextInterface;
use Waaseyaa\Foundation\FoundationServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

#[CoversClass(FoundationServiceProvider::class)]
final class FoundationServiceProviderTest extends TestCase
{
    public function test_community_context_binding_reuses_the_kernel_owned_instance(): void
    {
        $context = new CommunityContext();
        $context->set('community-a');
        $services = new class ($context) implements KernelServicesInterface {
            public function __construct(private readonly CommunityContextInterface $context) {}

            public function get(string $abstract): ?object
            {
                return $abstract === CommunityContextInterface::class ? $this->context : null;
            }
        };
        $provider = new FoundationServiceProvider();
        $provider->setKernelServices($services);
        $provider->register();

        self::assertSame($context, $provider->resolve(CommunityContextInterface::class));
        self::assertSame('community-a', $provider->resolve(CommunityContextInterface::class)->get());
    }
}
