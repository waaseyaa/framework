<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider\Capability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Foundation\Tests\Unit\ServiceProvider\Capability\Fixture\OptionalPackageAbsentProvider;
use Waaseyaa\Foundation\Tests\Unit\ServiceProvider\Capability\Fixture\OptionalPackagePresentProvider;

/** Optional-package contribution gate (#2826). */
#[CoversClass(OptionalPackageRequirement::class)]
#[CoversClass(OptionalPackageGate::class)]
final class OptionalPackageRequirementTest extends TestCase
{
    #[Test]
    public function a_requirement_is_satisfied_only_when_its_sentinel_is_autoloadable(): void
    {
        $present = new OptionalPackageRequirement('waaseyaa/foundation', ServiceProvider::class, 'commands');
        $presentInterface = new OptionalPackageRequirement('waaseyaa/foundation', RequiresOptionalPackagesInterface::class, 'commands');
        $absent = new OptionalPackageRequirement('waaseyaa/never-installed', OptionalPackageAbsentProvider::SENTINEL, 'commands');

        self::assertTrue($present->isSatisfied());
        self::assertTrue($presentInterface->isSatisfied());
        self::assertFalse($absent->isSatisfied());
    }

    #[Test]
    public function a_requirement_rejects_malformed_declarations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OptionalPackageRequirement('Not A Package', ServiceProvider::class, 'commands');
    }

    #[Test]
    public function a_requirement_rejects_a_leading_backslash_sentinel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OptionalPackageRequirement('waaseyaa/foundation', '\\' . ServiceProvider::class, 'commands');
    }

    #[Test]
    public function a_requirement_rejects_an_empty_purpose(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OptionalPackageRequirement('waaseyaa/foundation', ServiceProvider::class, '  ');
    }

    #[Test]
    public function the_gate_reaches_the_same_verdict_from_a_class_name_and_an_instance(): void
    {
        self::assertFalse(OptionalPackageGate::satisfied(OptionalPackageAbsentProvider::class));
        self::assertFalse(OptionalPackageGate::satisfied(new OptionalPackageAbsentProvider()));
        self::assertTrue(OptionalPackageGate::satisfied(OptionalPackagePresentProvider::class));
        self::assertTrue(OptionalPackageGate::satisfied(new OptionalPackagePresentProvider()));

        $missing = OptionalPackageGate::unsatisfied(OptionalPackageAbsentProvider::class);
        self::assertCount(1, $missing);
        self::assertSame('waaseyaa/never-installed', $missing[0]->package);
        self::assertSame(OptionalPackageAbsentProvider::SENTINEL, $missing[0]->sentinelClass);
    }

    #[Test]
    public function a_provider_without_optional_requirements_is_always_satisfied(): void
    {
        $plain = new class extends ServiceProvider {
            public function register(): void {}
        };

        self::assertTrue(OptionalPackageGate::satisfied($plain));
        self::assertSame([], OptionalPackageGate::unsatisfied($plain::class));
    }
}
