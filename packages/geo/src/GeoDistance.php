<?php

declare(strict_types=1);

namespace Waaseyaa\Geo;

/**
 * @api
 */
final class GeoDistance
{
    private const float EARTH_RADIUS_KM = 6371.0;

    /**
     * Calculate the great-circle distance between two points using the Haversine formula.
     *
     * @throws \InvalidArgumentException if any coordinate is out of range
     *                                   (lat: -90..90, lon: -180..180)
     * @return float Distance in kilometres
     */
    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        if ($lat1 < -90.0 || $lat1 > 90.0) {
            throw new \InvalidArgumentException(
                sprintf('lat1 must be between -90 and 90 degrees; got %s.', $lat1),
            );
        }
        if ($lat2 < -90.0 || $lat2 > 90.0) {
            throw new \InvalidArgumentException(
                sprintf('lat2 must be between -90 and 90 degrees; got %s.', $lat2),
            );
        }
        if ($lon1 < -180.0 || $lon1 > 180.0) {
            throw new \InvalidArgumentException(
                sprintf('lon1 must be between -180 and 180 degrees; got %s.', $lon1),
            );
        }
        if ($lon2 < -180.0 || $lon2 > 180.0) {
            throw new \InvalidArgumentException(
                sprintf('lon2 must be between -180 and 180 degrees; got %s.', $lon2),
            );
        }

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }
}
