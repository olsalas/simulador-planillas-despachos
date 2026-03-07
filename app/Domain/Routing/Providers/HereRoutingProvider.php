<?php

namespace App\Domain\Routing\Providers;

use App\Contracts\RoutingProvider;
use App\Domain\Routing\Support\FlexiblePolylineDecoder;
use App\Domain\Routing\Support\GeoMath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HereRoutingProvider implements RoutingProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly FlexiblePolylineDecoder $polylineDecoder,
    ) {
    }

    public function name(): string
    {
        return 'here';
    }

    public function buildRoute(array $stops, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('HERE provider selected but HERE_API_KEY is missing.');
        }

        if (count($stops) < 2) {
            return [
                'distance_meters' => 0.0,
                'duration_seconds' => 0.0,
                'geometry' => $stops,
                'legs' => [],
            ];
        }

        $origin = $this->formatPoint($stops[0]);
        $destination = $this->formatPoint($stops[count($stops) - 1]);
        $via = array_slice($stops, 1, -1);

        $query = http_build_query([
            'apikey' => $this->apiKey,
            'transportMode' => 'car',
            'routingMode' => 'fast',
            'origin' => $origin,
            'destination' => $destination,
            'return' => 'summary,polyline',
        ]);

        foreach ($via as $point) {
            $query .= '&via='.urlencode($this->formatPoint($point));
        }

        $response = Http::timeout(20)->acceptJson()->get("https://router.hereapi.com/v8/routes?{$query}");
        $response->throw();

        $route = data_get($response->json(), 'routes.0');
        if (! is_array($route)) {
            throw new RuntimeException('HERE route response did not include routes[0].');
        }

        $sections = data_get($route, 'sections', []);
        if (! is_array($sections) || $sections === []) {
            throw new RuntimeException('HERE route response did not include any sections.');
        }

        $distanceMeters = 0.0;
        $durationSeconds = 0.0;
        $geometry = [];
        $legs = [];

        foreach ($sections as $sectionIndex => $section) {
            $sectionDistance = (float) data_get($section, 'summary.length', 0);
            $sectionDuration = (float) data_get($section, 'summary.duration', 0);
            $distanceMeters += $sectionDistance;
            $durationSeconds += $sectionDuration;

            $sectionGeometry = [];
            $polyline = data_get($section, 'polyline');
            if (is_string($polyline) && $polyline !== '') {
                $sectionGeometry = $this->polylineDecoder->decode($polyline);
            }

            if ($sectionGeometry !== []) {
                if ($sectionIndex > 0) {
                    array_shift($sectionGeometry);
                }

                $geometry = array_merge($geometry, $sectionGeometry);
            }

            $legs[] = [
                'from' => [
                    'lat' => (float) data_get($section, 'departure.place.location.lat', 0),
                    'lng' => (float) data_get($section, 'departure.place.location.lng', 0),
                ],
                'to' => [
                    'lat' => (float) data_get($section, 'arrival.place.location.lat', 0),
                    'lng' => (float) data_get($section, 'arrival.place.location.lng', 0),
                ],
                'distance_meters' => $sectionDistance,
                'duration_seconds' => $sectionDuration,
            ];
        }

        if ($geometry === []) {
            $geometry = $stops;
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

    /**
     * @param  array{lat: float, lng: float}  $point
     */
    private function formatPoint(array $point): string
    {
        return $point['lat'].','.$point['lng'];
    }
}
