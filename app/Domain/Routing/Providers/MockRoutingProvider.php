<?php

namespace App\Domain\Routing\Providers;

use App\Contracts\RoutingProvider;
use App\Domain\Routing\Support\GeoMath;

class MockRoutingProvider implements RoutingProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function buildRoute(array $stops, array $options = []): array
    {
        $geometry = [];
        $distanceMeters = 0.0;
        $durationSeconds = 0.0;
        $legs = [];

        foreach ($stops as $index => $stop) {
            $geometry[] = [
                'lat' => (float) $stop['lat'],
                'lng' => (float) $stop['lng'],
            ];

            if ($index === 0) {
                continue;
            }

            $from = $stops[$index - 1];
            $to = $stop;
            $segmentDistance = GeoMath::distanceMeters($from, $to);
            $segmentDuration = $segmentDistance / 11.11; // ~40 km/h for mock ETA

            $distanceMeters += $segmentDistance;
            $durationSeconds += $segmentDuration;

            $legs[] = [
                'from_index' => $index - 1,
                'to_index' => $index,
                'distance_meters' => $segmentDistance,
                'duration_seconds' => $segmentDuration,
            ];
        }

        return [
            'distance_meters' => $distanceMeters,
            'duration_seconds' => $durationSeconds,
            'geometry' => $geometry,
            'legs' => $legs,
        ];
    }

    public function buildMatrix(array $stops, array $options = []): array
    {
        $distances = [];
        $durations = [];

        foreach ($stops as $i => $from) {
            $distanceRow = [];
            $durationRow = [];

            foreach ($stops as $j => $to) {
                if ($i === $j) {
                    $distanceRow[] = 0.0;
                    $durationRow[] = 0.0;
                    continue;
                }

                $distance = GeoMath::distanceMeters($from, $to);
                $distanceRow[] = $distance;
                $durationRow[] = $distance / 11.11;
            }

            $distances[] = $distanceRow;
            $durations[] = $durationRow;
        }

        return [
            'distances' => $distances,
            'durations' => $durations,
        ];
    }
}
