<?php

namespace App\Domain\Simulation;

use App\Contracts\RoutingProvider;
use App\Domain\Routing\Providers\MockRoutingProvider;
use App\Models\Depot;
use App\Models\InvoiceStop;
use App\Models\RouteBatch;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BuildSimulationRouteService
{
    public function __construct(
        private readonly RoutingProvider $routingProvider,
        private readonly MockRoutingProvider $mockRoutingProvider,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForBatch(RouteBatch $routeBatch, bool $returnToDepot = true): array
    {
        $routeBatch->loadMissing('driver.depot');

        $stops = InvoiceStop::query()
            ->with('branch:id,code,name,address,latitude,longitude')
            ->where('driver_id', $routeBatch->driver_id)
            ->whereDate('service_date', $routeBatch->service_date->toDateString())
            ->orderByRaw('planned_sequence asc nulls last')
            ->orderBy('id')
            ->get();

        $validStops = [];
        $excludedStops = [];

        foreach ($stops as $stop) {
            $branch = $stop->branch;

            if ($branch === null || $branch->latitude === null || $branch->longitude === null) {
                $excludedStops[] = [
                    'invoice_stop_id' => $stop->id,
                    'branch' => [
                        'id' => $branch?->id,
                        'code' => $branch?->code,
                        'name' => $branch?->name,
                    ],
                    'reason' => 'missing_branch_geocode',
                    'invoice_count' => $stop->invoice_count,
                ];
                continue;
            }

            $validStops[] = [
                'invoice_stop_id' => $stop->id,
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'branch_name' => $branch->name,
                'branch_address' => $branch->address,
                'invoice_count' => $stop->invoice_count,
                'lat' => (float) $branch->latitude,
                'lng' => (float) $branch->longitude,
            ];
        }

        return $this->buildPreviewForOrderedStops($routeBatch, $validStops, $returnToDepot, $excludedStops);
    }

    /**
     * @param  list<array<string, mixed>>  $orderedStops
     * @param  list<array<string, mixed>>  $excludedStops
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function buildPreviewForOrderedStops(
        RouteBatch $routeBatch,
        array $orderedStops,
        bool $returnToDepot = true,
        array $excludedStops = [],
        array $metadata = [],
    ): array {
        $routeBatch->loadMissing('driver.depot');

        $depot = $this->resolveDepotForStops($routeBatch, $orderedStops);
        $waypoints = $this->buildWaypoints($depot, $orderedStops, $returnToDepot);

        [$route, $effectiveProvider, $cacheHit] = $this->routeWithCache($waypoints, $returnToDepot);

        $numberedStops = [];
        foreach ($orderedStops as $index => $stop) {
            $numberedStops[] = [
                ...$stop,
                'sequence' => $index + 1,
            ];
        }

        return [
            'route_batch_id' => $routeBatch->id,
            'provider' => $effectiveProvider,
            'cache_hit' => $cacheHit,
            'return_to_depot' => $returnToDepot,
            'depot' => $depot,
            'stops' => $numberedStops,
            'excluded_stops' => $excludedStops,
            'metrics' => [
                'distance_meters' => (float) ($route['distance_meters'] ?? 0),
                'duration_seconds' => (float) ($route['duration_seconds'] ?? 0),
            ],
            'geometry' => $route['geometry'] ?? [],
            'legs' => $route['legs'] ?? [],
            'bounds' => $this->boundsFromGeometry($route['geometry'] ?? []),
            ...$metadata,
        ];
    }

    /**
     * @param  array{lat: float, lng: float, name: string, code: string|null, source: string}  $depot
     * @param  list<array{lat: float, lng: float}>  $stops
     * @return list<array{lat: float, lng: float}>
     */
    private function buildWaypoints(array $depot, array $stops, bool $returnToDepot): array
    {
        $points = [[
            'lat' => $depot['lat'],
            'lng' => $depot['lng'],
        ]];

        foreach ($stops as $stop) {
            $points[] = [
                'lat' => $stop['lat'],
                'lng' => $stop['lng'],
            ];
        }

        if ($returnToDepot && count($stops) > 0) {
            $points[] = [
                'lat' => $depot['lat'],
                'lng' => $depot['lng'],
            ];
        }

        return $points;
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $waypoints
     * @return array{0: array<string, mixed>, 1: string, 2: bool}
     */
    private function routeWithCache(array $waypoints, bool $returnToDepot): array
    {
        if (count($waypoints) <= 1) {
            return [[
                'distance_meters' => 0.0,
                'duration_seconds' => 0.0,
                'geometry' => $waypoints,
                'legs' => [],
            ], $this->routingProvider->name(), false];
        }

        $cacheTtl = now()->addSeconds((int) config('routing.cache_ttl_seconds', 86400));
        $primaryProviderName = $this->routingProvider->name();
        $primaryKey = $this->cacheKeyFor($primaryProviderName, $waypoints, $returnToDepot);

        $primaryCacheHit = Cache::has($primaryKey);
        if ($primaryCacheHit) {
            /** @var array<string, mixed> $cached */
            $cached = Cache::get($primaryKey, []);

            return [$cached, $primaryProviderName, true];
        }

        try {
            $route = $this->routingProvider->buildRoute($waypoints, [
                'return_to_depot' => $returnToDepot,
            ]);

            Cache::put($primaryKey, $route, $cacheTtl);

            return [$route, $primaryProviderName, false];
        } catch (Throwable $exception) {
            report($exception);
        }

        $fallbackProviderName = $this->mockRoutingProvider->name();
        $fallbackKey = $this->cacheKeyFor($fallbackProviderName, $waypoints, $returnToDepot);
        $fallbackCacheHit = Cache::has($fallbackKey);

        if ($fallbackCacheHit) {
            /** @var array<string, mixed> $cached */
            $cached = Cache::get($fallbackKey, []);

            return [$cached, $fallbackProviderName, true];
        }

        $fallbackRoute = $this->mockRoutingProvider->buildRoute($waypoints, [
            'return_to_depot' => $returnToDepot,
        ]);

        Cache::put($fallbackKey, $fallbackRoute, $cacheTtl);

        return [$fallbackRoute, $fallbackProviderName, false];
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $waypoints
     */
    private function cacheKeyFor(string $provider, array $waypoints, bool $returnToDepot): string
    {
        $normalizedWaypoints = array_map(
            fn (array $point): array => [
                'lat' => round((float) $point['lat'], 6),
                'lng' => round((float) $point['lng'], 6),
            ],
            $waypoints
        );

        $payload = [
            'provider' => $provider,
            'return_to_depot' => $returnToDepot,
            'waypoints' => $normalizedWaypoints,
        ];

        return 'routing:route:'.sha1((string) json_encode($payload));
    }

    /**
     * @param  RouteBatch  $routeBatch
     * @param  list<array{lat: float, lng: float}>  $validStops
     * @return array{lat: float, lng: float, name: string, code: string|null, address: string|null, source: string}
     */
    public function resolveDepotForStops(RouteBatch $routeBatch, array $validStops): array
    {
        $driverDepot = $routeBatch->driver?->depot;
        if ($driverDepot !== null && $driverDepot->latitude !== null && $driverDepot->longitude !== null) {
            return [
                'lat' => (float) $driverDepot->latitude,
                'lng' => (float) $driverDepot->longitude,
                'name' => $driverDepot->name,
                'code' => $driverDepot->code,
                'address' => $driverDepot->address,
                'source' => 'driver_depot',
            ];
        }

        $activeDepot = Depot::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->first();

        if ($activeDepot !== null) {
            return [
                'lat' => (float) $activeDepot->latitude,
                'lng' => (float) $activeDepot->longitude,
                'name' => $activeDepot->name,
                'code' => $activeDepot->code,
                'address' => $activeDepot->address,
                'source' => 'first_active_depot',
            ];
        }

        $fallbackLat = config('routing.fallback_depot.latitude');
        $fallbackLng = config('routing.fallback_depot.longitude');

        if ($fallbackLat !== null && $fallbackLng !== null) {
            return [
                'lat' => (float) $fallbackLat,
                'lng' => (float) $fallbackLng,
                'name' => (string) config('routing.fallback_depot.name', 'CEDIS Fallback'),
                'code' => null,
                'address' => null,
                'source' => 'config_fallback_depot',
            ];
        }

        if ($validStops !== []) {
            return [
                'lat' => (float) $validStops[0]['lat'],
                'lng' => (float) $validStops[0]['lng'],
                'name' => 'CEDIS temporal (primera parada)',
                'code' => null,
                'address' => null,
                'source' => 'first_stop_fallback',
            ];
        }

        return [
            'lat' => 19.432608,
            'lng' => -99.133209,
            'name' => 'CEDIS temporal (default)',
            'code' => null,
            'address' => null,
            'source' => 'hardcoded_fallback',
        ];
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $geometry
     * @return array<string, float>|null
     */
    private function boundsFromGeometry(array $geometry): ?array
    {
        if ($geometry === []) {
            return null;
        }

        $minLat = $geometry[0]['lat'];
        $maxLat = $geometry[0]['lat'];
        $minLng = $geometry[0]['lng'];
        $maxLng = $geometry[0]['lng'];

        foreach ($geometry as $point) {
            $minLat = min($minLat, $point['lat']);
            $maxLat = max($maxLat, $point['lat']);
            $minLng = min($minLng, $point['lng']);
            $maxLng = max($maxLng, $point['lng']);
        }

        return [
            'min_lat' => (float) $minLat,
            'max_lat' => (float) $maxLat,
            'min_lng' => (float) $minLng,
            'max_lng' => (float) $maxLng,
        ];
    }
}
