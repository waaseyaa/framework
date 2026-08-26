<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider\Capability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProviderCapabilitySource;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

#[CoversClass(ProviderCapabilitySource::class)]
final class ProviderCapabilitySourceTest extends TestCase
{
    #[Test]
    public function it_projects_live_matching_providers_in_manifest_order(): void
    {
        $providers = [];
        $source = new ProviderCapabilitySource(static function () use (&$providers): array {
            return $providers;
        });
        $first = new CapabilityFixtureProvider();
        $other = new class extends ServiceProvider {
            public function register(): void {}
        };
        $second = new CapabilityFixtureProvider();
        $providers = [$first, $other, $second];

        self::assertSame([$first, $second], $source->implementing(CapabilityFixture::class));
    }

    #[Test]
    public function a_non_interface_capability_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProviderCapabilitySource(static fn(): array => [])->implementing(self::class);
    }
}

interface CapabilityFixture {}

final class CapabilityFixtureProvider extends ServiceProvider implements CapabilityFixture
{
    public function register(): void {}
}
