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

    #[Test]
    public function throws_on_lat1_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lat1');
        GeoDistance::haversine(91.0, 0.0, 0.0, 0.0);
    }

    #[Test]
    public function throws_on_lat2_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lat2');
        GeoDistance::haversine(0.0, 0.0, -90.1, 0.0);
    }

    #[Test]
    public function throws_on_lon1_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lon1');
        GeoDistance::haversine(0.0, 181.0, 0.0, 0.0);
    }

    #[Test]
    public function throws_on_lon2_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lon2');
        GeoDistance::haversine(0.0, 0.0, 0.0, -180.1);
    }

    #[Test]
    public function accepts_boundary_values(): void
    {
        // Boundary values must be accepted without exception
        $distance = GeoDistance::haversine(-90.0, -180.0, 90.0, 180.0);
        $this->assertIsFloat($distance);
    }
}
