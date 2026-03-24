<?php

namespace App\Domain\Planning;

use App\Contracts\RoutingProvider;
use App\Domain\Routing\Providers\MockRoutingProvider;
use App\Domain\Simulation\BuildSimulationRouteService;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\PlanningScenario;
use App\Models\PlanningScenarioJourney;
use App\Models\PlanningScenarioStop;
use Illuminate\Support\Facades\DB;
use Throwable;

class GeneratePlanningScenarioAllocationService
{
    public function __construct(
        private readonly BuildSimulationRouteService $buildSimulationRouteService,
        private readonly RoutingProvider $routingProvider,
        private readonly MockRoutingProvider $mockRoutingProvider,
    ) {
    }

    public function generate(PlanningScenario $planningScenario): PlanningScenario
    {
        $planningScenario->loadMissing('depot');
        $configuration = $planningScenario->configuration ?? [];

        return DB::transaction(function () use ($planningScenario, $configuration): PlanningScenario {
            $planningScenario->journeys()->delete();

            $planningScenario->stops()
                ->whereIn('status', ['pending_assignment', 'assigned', 'unassigned'])
                ->update([
                    'planning_scenario_journey_id' => null,
                    'assigned_driver_id' => null,
                    'suggested_sequence' => null,
                    'assignment_reason' => null,
                    'status' => DB::raw("case when exclusion_reason is null then 'pending_assignment' else 'excluded' end"),
                ]);

            $eligibleStops = $planningScenario->stops()
                ->where('status', 'pending_assignment')
                ->orderByDesc('invoice_count')
                ->orderBy('branch_name')
                ->get();

            $activeDrivers = Driver::query()
                ->where('depot_id', $planningScenario->depot_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            if ($eligibleStops->isEmpty()) {
                return $this->finalizeScenario($planningScenario, 'allocation_ready');
            }

            if ($activeDrivers->isEmpty()) {
                $planningScenario->stops()
                    ->where('status', 'pending_assignment')
                    ->update([
                        'status' => 'unassigned',
                        'assignment_reason' => 'no_active_drivers',
                    ]);

                return $this->finalizeScenario($planningScenario, 'allocation_blocked');
            }

            $groups = $this->partitionStops(
                $planningScenario->depot,
                $activeDrivers->all(),
                $eligibleStops->all(),
                $configuration,
            );

            foreach ($groups['journeys'] as $index => $journeyGroup) {
                /** @var Driver $driver */
                $driver = $journeyGroup['driver'];
                $orderedStops = $this->orderJourneyStops($planningScenario->depot, $journeyGroup['stops']);

                $routePreview = $this->buildSimulationRouteService->buildPreviewFromDepot(
                    $this->depotPayload($planningScenario->depot),
                    array_map(fn (array $stop): array => $this->buildRouteStopPayload($stop), $orderedStops),
                    (bool) ($configuration['return_to_depot'] ?? true),
                    metadata: [
                        'label' => 'Propuesta base',
                        'source' => 'planning_allocation',
                        'algorithm' => 'angular_sweep_nearest_neighbor',
                    ],
                );

                $journey = $planningScenario->journeys()->create([
                    'driver_id' => $driver->id,
                    'name' => sprintf('Jornada propuesta %02d · %s', $index + 1, $driver->name),
                    'status' => 'generated',
                    'total_stops' => count($orderedStops),
                    'total_invoices' => array_sum(array_map(
                        fn (array $stop): int => (int) $stop['invoice_count'],
                        $orderedStops
                    )),
                    'summary' => [
                        'provider' => $routePreview['provider'],
                        'return_to_depot' => $routePreview['return_to_depot'],
                        'cache_hit' => $routePreview['cache_hit'],
                        'distance_meters' => $routePreview['metrics']['distance_meters'],
                        'duration_seconds' => $routePreview['metrics']['duration_seconds'],
                        'depot' => $routePreview['depot'],
                        'geometry' => $routePreview['geometry'],
                        'bounds' => $routePreview['bounds'],
                    ],
                ]);

                foreach ($orderedStops as $sequence => $stop) {
                    /** @var PlanningScenarioStop $stopModel */
                    $stopModel = $stop['model'];
                    $stopModel->update([
                        'planning_scenario_journey_id' => $journey->id,
                        'assigned_driver_id' => $driver->id,
                        'suggested_sequence' => $sequence + 1,
                        'assignment_reason' => 'angular_sweep_partition',
                        'status' => 'assigned',
                    ]);
                }
            }

            foreach ($groups['unassigned'] as $unassignedStop) {
                /** @var PlanningScenarioStop $stopModel */
                $stopModel = $unassignedStop['model'];
                $stopModel->update([
                    'status' => 'unassigned',
                    'assignment_reason' => $unassignedStop['reason'],
                ]);
            }

            $hasUnassigned = $planningScenario->stops()->where('status', 'unassigned')->exists();

            return $this->finalizeScenario(
                $planningScenario,
                $hasUnassigned ? 'allocation_partial' : 'allocation_ready'
            );
        });
    }

    /**
     * @param  list<Driver>  $drivers
     * @param  list<PlanningScenarioStop>  $stops
     * @param  array<string, mixed>  $configuration
     * @return array{
     *     journeys: list<array<string, mixed>>,
     *     unassigned: list<array<string, mixed>>
     * }
     */
    public function preview(Depot $depot, array $drivers, array $stops, array $configuration = []): array
    {
        if ($stops === []) {
            return [
                'journeys' => [],
                'unassigned' => [],
            ];
        }

        if ($drivers === []) {
            return [
                'journeys' => [],
                'unassigned' => array_map(
                    fn (PlanningScenarioStop $stop): array => $this->buildUnassignedStopPayload($stop, 'no_active_drivers'),
                    $stops,
                ),
            ];
        }

        $groups = $this->partitionStops($depot, $drivers, $stops, $configuration);
        $journeys = [];

        foreach ($groups['journeys'] as $index => $journeyGroup) {
            /** @var Driver $driver */
            $driver = $journeyGroup['driver'];
            $orderedStops = $this->orderJourneyStops($depot, $journeyGroup['stops']);

            $routePreview = $this->buildSimulationRouteService->buildPreviewFromDepot(
                $this->depotPayload($depot),
                array_map(fn (array $stop): array => $this->buildRouteStopPayload($stop), $orderedStops),
                (bool) ($configuration['return_to_depot'] ?? true),
                metadata: [
                    'label' => 'Propuesta base',
                    'source' => 'planning_allocation_preview',
                    'algorithm' => 'angular_sweep_nearest_neighbor',
                ],
            );

            $journeys[] = [
                'journey_kind' => 'proposed',
                'driver_key' => 'driver:'.$driver->id,
                'driver' => [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'external_id' => $driver->external_id,
                    'is_active' => (bool) $driver->is_active,
                ],
                'name' => sprintf('Jornada propuesta %02d · %s', $index + 1, $driver->name),
                'status' => 'preview',
                'total_stops' => count($orderedStops),
                'total_invoices' => array_sum(array_map(
                    fn (array $stop): int => (int) $stop['invoice_count'],
                    $orderedStops,
                )),
                'summary' => [
                    'distance_meters' => (float) $routePreview['metrics']['distance_meters'],
                    'duration_seconds' => (float) $routePreview['metrics']['duration_seconds'],
                    'provider' => $routePreview['provider'],
                    'cache_hit' => $routePreview['cache_hit'],
                    'return_to_depot' => $routePreview['return_to_depot'],
                ],
                'route_preview' => $routePreview,
                'stops' => array_map(
                    fn (array $stop, int $sequence): array => $this->buildPreviewStopPayload($stop['model'], $sequence + 1),
                    $orderedStops,
                    array_keys($orderedStops),
                ),
            ];
        }

        return [
            'journeys' => $journeys,
            'unassigned' => array_map(
                fn (array $stop): array => $this->buildUnassignedStopPayload($stop['model'], $stop['reason']),
                $groups['unassigned'],
            ),
        ];
    }

    /**
     * @param  list<Driver>  $drivers
     * @param  list<PlanningScenarioStop>  $stops
     * @param  array<string, mixed>  $configuration
     * @return array{
     *     journeys: list<array{driver: Driver, stops: list<array<string, mixed>>}>,
     *     unassigned: list<array{model: PlanningScenarioStop, reason: string}>
     * }
     */
    private function partitionStops(Depot $depot, array $drivers, array $stops, array $configuration): array
    {
        $sortedStops = array_map(
            fn (PlanningScenarioStop $stop): array => [
                'model' => $stop,
                'bearing' => $this->bearingFromDepot($depot, $stop),
                'distance' => $this->distanceFromDepot($depot, $stop),
                'invoice_count' => (int) $stop->invoice_count,
            ],
            $stops
        );

        usort($sortedStops, function (array $left, array $right): int {
            $bearingCompare = $left['bearing'] <=> $right['bearing'];
            if ($bearingCompare !== 0) {
                return $bearingCompare;
            }

            $distanceCompare = $left['distance'] <=> $right['distance'];
            if ($distanceCompare !== 0) {
                return $distanceCompare;
            }

            return strcmp((string) $left['model']->stop_key, (string) $right['model']->stop_key);
        });

        $maxStopsPerDriver = $configuration['max_stops_per_driver'] ?? null;
        $maxInvoicesPerJourney = $configuration['max_invoices_per_journey'] ?? null;
        $driverCount = min(count($drivers), count($sortedStops));
        $remainingStops = $sortedStops;
        $journeys = [];
        $unassigned = [];

        for ($index = 0; $index < $driverCount; $index++) {
            $remainingDrivers = $driverCount - $index;
            $groupTargetStops = (int) ceil(count($remainingStops) / max(1, $remainingDrivers));
            $group = [];
            $groupInvoices = 0;

            while ($remainingStops !== []) {
                $candidate = $remainingStops[0];

                if ($maxStopsPerDriver !== null && count($group) >= (int) $maxStopsPerDriver) {
                    break;
                }

                if ($maxInvoicesPerJourney !== null
                    && ($groupInvoices + $candidate['invoice_count']) > (int) $maxInvoicesPerJourney) {
                    if ($group === []) {
                        $unassigned[] = [
                            'model' => $candidate['model'],
                            'reason' => 'max_invoices_per_journey',
                        ];
                        array_shift($remainingStops);
                        continue;
                    }

                    break;
                }

                $group[] = array_shift($remainingStops);
                $groupInvoices += $candidate['invoice_count'];

                if (count($group) >= $groupTargetStops) {
                    break;
                }
            }

            if ($group !== []) {
                $journeys[] = [
                    'driver' => $drivers[$index],
                    'stops' => $group,
                ];
            }
        }

        foreach ($remainingStops as $stop) {
            $unassigned[] = [
                'model' => $stop['model'],
                'reason' => 'driver_capacity_exhausted',
            ];
        }

        return [
            'journeys' => $journeys,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @return list<array<string, mixed>>
     */
    private function orderJourneyStops(Depot $depot, array $stops): array
    {
        if ($stops === []) {
            return [];
        }

        $points = array_merge(
            [[
                'lat' => (float) $depot->latitude,
                'lng' => (float) $depot->longitude,
            ]],
            array_map(fn (array $stop): array => [
                'lat' => (float) $stop['model']->latitude,
                'lng' => (float) $stop['model']->longitude,
            ], $stops)
        );

        $matrix = $this->buildMatrixWithFallback($points);
        $durations = $matrix['durations'] ?? [];
        $remaining = range(1, count($stops));
        $currentIndex = 0;
        $ordered = [];

        while ($remaining !== []) {
            $bestCandidate = null;
            $bestDuration = INF;

            foreach ($remaining as $candidateIndex) {
                $candidateDuration = $durations[$currentIndex][$candidateIndex] ?? INF;
                $candidate = $stops[$candidateIndex - 1];

                if ($candidateDuration < $bestDuration) {
                    $bestDuration = $candidateDuration;
                    $bestCandidate = $candidateIndex;
                    continue;
                }

                if ($candidateDuration === $bestDuration && $bestCandidate !== null) {
                    $currentBest = $stops[$bestCandidate - 1];
                    if ($this->compareStopIdentity($candidate, $currentBest) < 0) {
                        $bestCandidate = $candidateIndex;
                    }
                }
            }

            if ($bestCandidate === null) {
                break;
            }

            $ordered[] = $stops[$bestCandidate - 1];
            $currentIndex = $bestCandidate;
            $remaining = array_values(array_filter(
                $remaining,
                fn (int $candidate): bool => $candidate !== $bestCandidate
            ));
        }

        return $ordered;
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $points
     * @return array{distances: list<list<float|null>>, durations: list<list<float|null>>}
     */
    private function buildMatrixWithFallback(array $points): array
    {
        if (count($points) <= 1) {
            return [
                'distances' => [[0.0]],
                'durations' => [[0.0]],
            ];
        }

        try {
            return $this->routingProvider->buildMatrix($points);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->mockRoutingProvider->buildMatrix($points);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareStopIdentity(array $left, array $right): int
    {
        return strcmp((string) $left['model']->stop_key, (string) $right['model']->stop_key);
    }

    private function bearingFromDepot(Depot $depot, PlanningScenarioStop $stop): float
    {
        $lat1 = deg2rad((float) $depot->latitude);
        $lng1 = deg2rad((float) $depot->longitude);
        $lat2 = deg2rad((float) $stop->latitude);
        $lng2 = deg2rad((float) $stop->longitude);

        $y = sin($lng2 - $lng1) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lng2 - $lng1);
        $bearing = rad2deg(atan2($y, $x));

        return fmod(($bearing + 360.0), 360.0);
    }

    private function distanceFromDepot(Depot $depot, PlanningScenarioStop $stop): float
    {
        $earthRadiusMeters = 6371000.0;

        $lat1 = deg2rad((float) $depot->latitude);
        $lng1 = deg2rad((float) $depot->longitude);
        $lat2 = deg2rad((float) $stop->latitude);
        $lng2 = deg2rad((float) $stop->longitude);

        $latDelta = $lat2 - $lat1;
        $lngDelta = $lng2 - $lng1;

        $a = sin($latDelta / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($lngDelta / 2) ** 2;

        return 2 * $earthRadiusMeters * asin(min(1.0, sqrt($a)));
    }

    /**
     * @return array{lat: float, lng: float, name: string, code: string|null, address: string|null, source: string}
     */
    private function depotPayload(Depot $depot): array
    {
        return [
            'lat' => (float) $depot->latitude,
            'lng' => (float) $depot->longitude,
            'name' => $depot->name,
            'code' => $depot->code,
            'address' => $depot->address,
            'source' => 'planning_scenario_depot',
        ];
    }

    /**
     * @param  array<string, mixed>  $stop
     * @return array<string, mixed>
     */
    private function buildRouteStopPayload(array $stop): array
    {
        /** @var PlanningScenarioStop $model */
        $model = $stop['model'];

        return [
            'planning_scenario_stop_id' => $model->id,
            'branch_id' => $model->branch_id,
            'branch_code' => $model->branch_code,
            'branch_name' => $model->branch_name,
            'branch_address' => $model->branch_address,
            'invoice_count' => $model->invoice_count,
            'historical_sequence' => $model->historical_sequence_min,
            'lat' => (float) $model->latitude,
            'lng' => (float) $model->longitude,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreviewStopPayload(PlanningScenarioStop $stop, int $sequence): array
    {
        return [
            'planning_scenario_stop_id' => $stop->id,
            'stop_key' => $stop->stop_key,
            'branch_id' => $stop->branch_id,
            'branch_code' => $stop->branch_code,
            'branch_name' => $stop->branch_name,
            'branch_address' => $stop->branch_address,
            'invoice_count' => (int) $stop->invoice_count,
            'historical_sequence_min' => $stop->historical_sequence_min,
            'sequence' => $sequence,
            'suggested_sequence' => $sequence,
            'lat' => (float) $stop->latitude,
            'lng' => (float) $stop->longitude,
            'assignment_reason' => 'angular_sweep_partition',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUnassignedStopPayload(PlanningScenarioStop $stop, string $reason): array
    {
        return [
            'planning_scenario_stop_id' => $stop->id,
            'stop_key' => $stop->stop_key,
            'branch_id' => $stop->branch_id,
            'branch_code' => $stop->branch_code,
            'branch_name' => $stop->branch_name,
            'branch_address' => $stop->branch_address,
            'invoice_count' => (int) $stop->invoice_count,
            'historical_sequence_min' => $stop->historical_sequence_min,
            'reason' => $reason,
        ];
    }

    private function finalizeScenario(PlanningScenario $planningScenario, string $status): PlanningScenario
    {
        $planningScenario->refresh();

        $journeys = $planningScenario->journeys()->get();
        $assignedStops = $planningScenario->stops()->where('status', 'assigned');
        $unassignedStops = $planningScenario->stops()->where('status', 'unassigned');

        $summary = [
            ...($planningScenario->summary ?? []),
            'proposed_journeys' => $journeys->count(),
            'allocated_stops' => $assignedStops->count(),
            'allocated_invoices' => (int) $assignedStops->sum('invoice_count'),
            'unassigned_stops' => $unassignedStops->count(),
            'unassigned_invoices' => (int) $unassignedStops->sum('invoice_count'),
        ];

        $planningScenario->update([
            'status' => $status,
            'summary' => $summary,
            'last_generated_at' => now(),
        ]);

        return $planningScenario->fresh(['depot', 'creator']);
    }
}
