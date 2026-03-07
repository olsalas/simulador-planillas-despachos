<?php

namespace App\Domain\Routing\Support;

final class GeoMath
{
    /**
     * @param  array{lat: float, lng: float}  $from
     * @param  array{lat: float, lng: float}  $to
     */
    public static function distanceMeters(array $from, array $to): float
    {
        $earthRadiusMeters = 6371000.0;

        $latFrom = deg2rad($from['lat']);
        $latTo = deg2rad($to['lat']);
        $deltaLat = deg2rad($to['lat'] - $from['lat']);
        $deltaLng = deg2rad($to['lng'] - $from['lng']);

        $a = sin($deltaLat / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($deltaLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMeters * $c;
    }
}
