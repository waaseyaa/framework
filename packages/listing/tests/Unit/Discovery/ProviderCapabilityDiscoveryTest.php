<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProviderCapabilitySource;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ServiceProvider;

#[CoversClass(ServiceProvider::class)]
#[CoversClass(ListingDefinition::class)]
final class ProviderCapabilityDiscoveryTest extends TestCase
{
    private function app(): \Waaseyaa\Foundation\ServiceProvider\ServiceProvider
    {
        return new class extends \Waaseyaa\Foundation\ServiceProvider\ServiceProvider implements HasListingsInterface {
            public function register(): void {}
            public function listings(): array
            {
                return [new ListingDefinition(id: 'public_projection', entityType: 'article')];
            }
        };
    }

    private function listing(ProviderCapabilitySource $source): ServiceProvider
    {
        $listing = new ServiceProvider();
        $listing->setKernelServices(new class ($source) implements KernelServicesInterface {
            public function __construct(private readonly ProviderCapabilitySource $source) {}
            public function get(string $abstract): ?object
            {
                return $abstract === ProviderCapabilitySource::class ? $this->source : null;
            }
        });
        $listing->register();

        return $listing;
    }

    #[Test]
    public function publicCapabilitySourceSeesLateRegistrationBeforeDiscovery(): void
    {
        $providers = [];
        $source = new ProviderCapabilitySource(static function () use (&$providers): array {
            return $providers;
        });
        $listing = $this->listing($source);
        $providers[] = $this->app();
        self::assertSame('public_projection', $listing->resolve(ListingDefinitionRegistry::class)->get('public_projection')->id);
    }

    #[Test]
    public function publicDiscoveryRefusesDuplicateDeclarations(): void
    {
        $providers = [$this->app(), $this->app()];
        $listing = $this->listing(new ProviderCapabilitySource(static fn(): array => $providers));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate listing id');
        $listing->resolve(ListingDefinitionRegistry::class);
    }

    #[Test]
    public function standaloneProviderHasNoDiscoveredDefinitions(): void
    {
        $listing = new ServiceProvider();
        $listing->register();
        self::assertSame([], $listing->resolve(ListingDefinitionRegistry::class)->all());
    }
}
