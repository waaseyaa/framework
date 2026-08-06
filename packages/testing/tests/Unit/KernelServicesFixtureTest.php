<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Testing\Kernel\KernelServicesFixture;

#[CoversClass(KernelServicesFixture::class)]
final class KernelServicesFixtureTest extends TestCase
{
    #[Test]
    public function it_resolves_a_typed_service_map_and_optional_fallback(): void
    {
        $mapped = new \stdClass();
        $fallback = new \ArrayObject();
        $services = new KernelServicesFixture(
            ['mapped' => $mapped],
            static fn (string $abstract): ?object => $abstract === 'fallback' ? $fallback : null,
        );

        self::assertSame($mapped, $services->get('mapped'));
        self::assertSame($fallback, $services->get('fallback'));
        self::assertNull($services->get('missing'));
    }
}
