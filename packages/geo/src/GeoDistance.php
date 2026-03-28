<?php

declare(strict_types=1);

namespace Waaseyaa\Geo;

final class GeoDistance
{
    private const float EARTH_RADIUS_KM = 6371.0;

    /**
     * Calculate the great-circle distance between two points using the Haversine formula.
     *
     * @return float Distance in kilometres
     */
    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }
}
