<?php

declare(strict_types=1);

namespace Waaseyaa\Geo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Geo\GeoDistance;

#[CoversClass(GeoDistance::class)]
final class GeoDistanceTest extends TestCase
{
    #[Test]
    public function calculates_distance_between_two_points(): void
    {
        // Toronto (43.6532, -79.3832) to Ottawa (45.4215, -75.6972)
        $distance = GeoDistance::haversine(43.6532, -79.3832, 45.4215, -75.6972);
        $this->assertEqualsWithDelta(353.0, $distance, 5.0);
    }

    #[Test]
    public function same_point_returns_zero(): void
    {
        $this->assertSame(0.0, GeoDistance::haversine(43.0, -79.0, 43.0, -79.0));
    }

    #[Test]
    public function handles_antipodal_points(): void
    {
        // North pole to south pole ≈ 20015 km
        $distance = GeoDistance::haversine(90.0, 0.0, -90.0, 0.0);
        $this->assertEqualsWithDelta(20015.0, $distance, 100.0);
    }
}
