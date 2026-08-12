<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider\Capability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityDeclaration;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityRegistry;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesCapabilitiesInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiredCapabilityUnavailableException;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresCapabilitiesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class CapabilityRegistryTest extends TestCase
{
    #[Test]
    public function compatibleRequirementPassesAndIdenticalDuplicateDeclarationsAreIdempotent(): void
    {
        $registry = new CapabilityRegistry();
        $registry->validate([
            $this->provider('configuration.authority.v1', 1, 'same'),
            $this->provider('configuration.authority.v1', 1, 'same'),
            $this->consumer('configuration.authority.v1', 1),
        ]);

        self::assertTrue(true);
    }

    #[Test]
    public function missingOrIncompatibleCapabilityFailsBeforeBoot(): void
    {
        $this->expectException(RequiredCapabilityUnavailableException::class);
        $this->expectExceptionMessage('no compatible authority is registered');

        new CapabilityRegistry()->validate([$this->consumer('configuration.authority.v1', 1)]);
    }

    #[Test]
    public function divergentDuplicateAuthoritiesFailClosed(): void
    {
        $this->expectException(RequiredCapabilityUnavailableException::class);
        $this->expectExceptionMessage('divergent providers');

        new CapabilityRegistry()->validate([
            $this->provider('configuration.authority.v1', 1, 'first'),
            $this->provider('configuration.authority.v1', 1, 'second'),
        ]);
    }

    private function provider(string $id, int $version, string $fingerprint): ServiceProvider
    {
        return new class($id, $version, $fingerprint) extends ServiceProvider implements ProvidesCapabilitiesInterface {
            public function __construct(
                private readonly string $id,
                private readonly int $version,
                private readonly string $fingerprint,
            ) {}

            public function register(): void {}

            public function capabilityDeclarations(): iterable
            {
                yield new CapabilityDeclaration($this->id, $this->version, $this->fingerprint);
            }
        };
    }

    private function consumer(string $id, int $version): ServiceProvider
    {
        return new class($id, $version) extends ServiceProvider implements RequiresCapabilitiesInterface {
            public function __construct(private readonly string $id, private readonly int $version) {}

            public function register(): void {}

            public function capabilityRequirements(): iterable
            {
                yield CapabilityRequirement::exact($this->id, $this->version);
            }
        };
    }
}
