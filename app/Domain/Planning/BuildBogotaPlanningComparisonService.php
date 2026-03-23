<?php

namespace App\Domain\Planning;

use App\Domain\Simulation\BuildSimulationRouteService;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\PlanningScenario;
use App\Models\PlanningScenarioStop;
use Illuminate\Support\Collection;

class BuildBogotaPlanningComparisonService
{
    public function __construct(
        private readonly BogotaScope $bogotaScope,
        private readonly BuildSimulationRouteService $buildSimulationRouteService,
        private readonly GeneratePlanningScenarioAllocationService $generatePlanningScenarioAllocationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(PlanningScenario $planningScenario): array
    {
        $planningScenario->loadMissing('depot', 'stops');

        $candidateStops = $planningScenario->stops
            ->whereIn('status', ['pending_assignment', 'assigned', 'unassigned'])
            ->values();

        $bogotaStops = $candidateStops
            ->filter(fn (PlanningScenarioStop $stop): bool => $this->isEligibleBogotaStop($stop))
            ->values();

        $outsideBogotaStops = $candidateStops
            ->reject(fn (PlanningScenarioStop $stop): bool => $this->isEligibleBogotaStop($stop))
            ->map(fn (PlanningScenarioStop $stop): array => [
                'planning_scenario_stop_id' => $stop->id,
                'stop_key' => $stop->stop_key,
                'branch_code' => $stop->branch_code,
                'branch_name' => $stop->branch_name,
                'branch_address' => $stop->branch_address,
                'invoice_count' => (int) $stop->invoice_count,
                'reason' => 'outside_bogota_scope',
            ])
            ->values()
            ->all();

        $dataQualityExcludedStops = $planningScenario->stops
            ->where('status', 'excluded')
            ->map(fn (PlanningScenarioStop $stop): array => [
                'planning_scenario_stop_id' => $stop->id,
                'stop_key' => $stop->stop_key,
                'branch_code' => $stop->branch_code,
                'branch_name' => $stop->branch_name,
                'branch_address' => $stop->branch_address,
                'invoice_count' => (int) $stop->invoice_count,
                'reason' => $stop->exclusion_reason,
            ])
            ->values()
            ->all();

        $invoiceIds = $bogotaStops
            ->flatMap(fn (PlanningScenarioStop $stop): array => $stop->invoice_ids ?? [])
            ->unique()
            ->values();

        $historicalInvoices = $invoiceIds->isEmpty()
            ? collect()
            : Invoice::query()
                ->with([
                    'branch:id,code,name,address,latitude,longitude',
                    'driver:id,name,external_id,depot_id,is_active',
                ])
                ->whereIn('id', $invoiceIds->all())
                ->orderBy('driver_id')
                ->orderByRaw('historical_sequence asc nulls last')
                ->orderBy('id')
                ->get();

        $historicalJourneys = $this->buildHistoricalJourneys($planningScenario->depot, $historicalInvoices);
        $historicalDistribution = $this->buildHistoricalDistribution($historicalInvoices);

        $activeDrivers = Driver::query()
            ->where('depot_id', $planningScenario->depot_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $proposal = $this->generatePlanningScenarioAllocationService->preview(
            $planningScenario->depot,
            $activeDrivers->all(),
            $bogotaStops->all(),
            $planningScenario->configuration ?? [],
        );

        $historicalJourneyLookup = $this->indexJourneysByDriverKey($historicalJourneys);
        $proposedJourneyLookup = $this->indexJourneysByDriverKey($proposal['journeys']);
        $proposedDriverByStop = $this->indexProposedDriversByStop($proposal['journeys']);

        $historicalJourneys = $this->enrichHistoricalJourneys($historicalJourneys, $proposedDriverByStop);
        $proposedJourneys = $this->enrichProposedJourneys($proposal['journeys'], $historicalDistribution);

        return [
            'cut' => [
                'city' => $this->bogotaScope->cityLabel(),
                'label' => $this->bogotaScope->operationalCutLabel(),
                'definition' => $this->bogotaScope->operationalCutDefinition(),
                'service_date' => $planningScenario->service_date->toDateString(),
                'depot' => [
                    'id' => $planningScenario->depot?->id,
                    'code' => $planningScenario->depot?->code,
                    'name' => $planningScenario->depot?->name,
                    'address' => $planningScenario->depot?->address,
                ],
            ],
            'summary' => $this->buildSummary(
                $historicalJourneys,
                $proposedJourneys,
                $proposal['unassigned'],
                $outsideBogotaStops,
                $dataQualityExcludedStops,
                $historicalDistribution,
            ),
            'historical_journeys' => $historicalJourneys,
            'proposed_journeys' => $proposedJourneys,
            'driver_comparisons' => $this->buildDriverComparisons($historicalJourneyLookup, $proposedJourneyLookup),
            'unassigned_stops' => $proposal['unassigned'],
            'excluded_stops' => [
                'outside_bogota' => $outsideBogotaStops,
                'data_quality' => $dataQualityExcludedStops,
            ],
        ];
    }

    private function isEligibleBogotaStop(PlanningScenarioStop $stop): bool
    {
        return $stop->latitude !== null
            && $stop->longitude !== null
            && $this->bogotaScope->isBogotaStop($stop);
    }

    /**
     * @param  Collection<int, Invoice>  $historicalInvoices
     * @return list<array<string, mixed>>
     */
    private function buildHistoricalJourneys(Depot $depot, Collection $historicalInvoices): array
    {
        if ($historicalInvoices->isEmpty()) {
            return [];
        }

        $journeys = [];

        $groupedByDriver = $historicalInvoices
            ->groupBy('driver_id')
            ->sortBy(fn (Collection $driverInvoices): string => (string) $driverInvoices->first()?->driver?->name);

        foreach ($groupedByDriver as $driverInvoices) {
            $driver = $driverInvoices->first()?->driver;
            if ($driver === null) {
                continue;
            }

            $stops = $driverInvoices
                ->groupBy('branch_id')
                ->map(function (Collection $branchInvoices) {
                    $branch = $branchInvoices->first()?->branch;

                    return [
                        'stop_key' => 'branch:'.$branch->id,
                        'branch_id' => $branch->id,
                        'branch_code' => $branch->code,
                        'branch_name' => $branch->name,
                        'branch_address' => $branch->address,
                        'invoice_count' => $branchInvoices->count(),
                        'historical_sequence' => $branchInvoices
                            ->pluck('historical_sequence')
                            ->filter(fn ($value): bool => $value !== null)
                            ->min(),
                        'lat' => (float) $branch->latitude,
                        'lng' => (float) $branch->longitude,
                    ];
                })
                ->values()
                ->all();

            usort($stops, function (array $left, array $right): int {
                $leftSequence = $left['historical_sequence'] ?? PHP_INT_MAX;
                $rightSequence = $right['historical_sequence'] ?? PHP_INT_MAX;

                if ($leftSequence !== $rightSequence) {
                    return $leftSequence <=> $rightSequence;
                }

                return strcmp((string) $left['stop_key'], (string) $right['stop_key']);
            });

            $routePreview = $this->buildSimulationRouteService->buildPreviewFromDepot(
                $this->depotPayload($depot),
                array_map(fn (array $stop): array => [
                    'stop_key' => $stop['stop_key'],
                    'branch_id' => $stop['branch_id'],
                    'branch_code' => $stop['branch_code'],
                    'branch_name' => $stop['branch_name'],
                    'branch_address' => $stop['branch_address'],
                    'invoice_count' => $stop['invoice_count'],
                    'historical_sequence' => $stop['historical_sequence'],
                    'lat' => $stop['lat'],
                    'lng' => $stop['lng'],
                ], $stops),
                true,
                metadata: [
                    'label' => 'Como fue',
                    'source' => 'historical_allocation',
                ],
            );

            $journeys[] = [
                'journey_kind' => 'historical',
                'driver_key' => $this->driverKey($driver),
                'driver' => $this->driverPayload($driver),
                'name' => sprintf('Jornada real · %s', $driver->name),
                'status' => 'historical',
                'total_stops' => count($routePreview['stops']),
                'total_invoices' => array_sum(array_map(
                    fn (array $stop): int => (int) $stop['invoice_count'],
                    $routePreview['stops']
                )),
                'summary' => [
                    'distance_meters' => (float) $routePreview['metrics']['distance_meters'],
                    'duration_seconds' => (float) $routePreview['metrics']['duration_seconds'],
                    'provider' => $routePreview['provider'],
                    'cache_hit' => $routePreview['cache_hit'],
                    'return_to_depot' => $routePreview['return_to_depot'],
                ],
                'route_preview' => $routePreview,
                'stops' => array_map(fn (array $stop): array => [
                    ...$stop,
                    'proposed_driver' => null,
                ], $routePreview['stops']),
            ];
        }

        return array_values($journeys);
    }

    /**
     * @param  Collection<int, Invoice>  $historicalInvoices
     * @return array<string, array<string, mixed>>
     */
    private function buildHistoricalDistribution(Collection $historicalInvoices): array
    {
        return $historicalInvoices
            ->groupBy('branch_id')
            ->mapWithKeys(function (Collection $branchInvoices, int $branchId): array {
                $drivers = $branchInvoices
                    ->groupBy('driver_id')
                    ->map(function (Collection $driverInvoices): array {
                        $driver = $driverInvoices->first()?->driver;

                        return [
                            'driver_key' => $this->driverKey($driver),
                            'driver' => $this->driverPayload($driver),
                            'invoice_count' => $driverInvoices->count(),
                        ];
                    })
                    ->sortByDesc('invoice_count')
                    ->values()
                    ->all();

                return [
                    'branch:'.$branchId => [
                        'drivers' => $drivers,
                        'by_driver_id' => $branchInvoices
                            ->groupBy('driver_id')
                            ->map(fn (Collection $driverInvoices): int => $driverInvoices->count())
                            ->all(),
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $journeys
     * @return array<string, array<string, mixed>>
     */
    private function indexJourneysByDriverKey(array $journeys): array
    {
        $lookup = [];

        foreach ($journeys as $journey) {
            $lookup[$journey['driver_key']] = $journey;
        }

        return $lookup;
    }

    /**
     * @param  list<array<string, mixed>>  $journeys
     * @return array<string, array<string, mixed>>
     */
    private function indexProposedDriversByStop(array $journeys): array
    {
        $lookup = [];

        foreach ($journeys as $journey) {
            foreach ($journey['stops'] as $stop) {
                $lookup[$stop['stop_key']] = $journey['driver'];
            }
        }

        return $lookup;
    }

    /**
     * @param  list<array<string, mixed>>  $journeys
     * @param  array<string, array<string, mixed>>  $proposedDriverByStop
     * @return list<array<string, mixed>>
     */
    private function enrichHistoricalJourneys(array $journeys, array $proposedDriverByStop): array
    {
        return array_map(function (array $journey) use ($proposedDriverByStop): array {
            $journey['stops'] = array_map(function (array $stop) use ($proposedDriverByStop): array {
                return [
                    ...$stop,
                    'proposed_driver' => $proposedDriverByStop[$stop['stop_key']] ?? null,
                ];
            }, $journey['stops']);

            return $journey;
        }, $journeys);
    }

    /**
     * @param  list<array<string, mixed>>  $journeys
     * @param  array<string, array<string, mixed>>  $historicalDistribution
     * @return list<array<string, mixed>>
     */
    private function enrichProposedJourneys(array $journeys, array $historicalDistribution): array
    {
        return array_map(function (array $journey) use ($historicalDistribution): array {
            $journey['stops'] = array_map(function (array $stop) use ($historicalDistribution): array {
                return [
                    ...$stop,
                    'historical_assignments' => $historicalDistribution[$stop['stop_key']]['drivers'] ?? [],
                ];
            }, $journey['stops']);

            return $journey;
        }, $journeys);
    }

    /**
     * @param  array<string, array<string, mixed>>  $historicalJourneys
     * @param  array<string, array<string, mixed>>  $proposedJourneys
     * @return list<array<string, mixed>>
     */
    private function buildDriverComparisons(array $historicalJourneys, array $proposedJourneys): array
    {
        $driverKeys = array_values(array_unique(array_merge(
            array_keys($historicalJourneys),
            array_keys($proposedJourneys),
        )));

        $rows = array_map(function (string $driverKey) use ($historicalJourneys, $proposedJourneys): array {
            $historical = $historicalJourneys[$driverKey] ?? null;
            $proposed = $proposedJourneys[$driverKey] ?? null;
            $driver = $historical['driver'] ?? $proposed['driver'] ?? [
                'id' => null,
                'name' => 'Sin conductor',
                'external_id' => null,
                'is_active' => false,
            ];

            return [
                'driver_key' => $driverKey,
                'driver' => $driver,
                'status' => $this->driverComparisonStatus($historical, $proposed),
                'historical' => $this->journeySummaryPayload($historical),
                'proposed' => $this->journeySummaryPayload($proposed),
                'delta' => [
                    'stops' => $this->journeyMetric($proposed, 'total_stops') - $this->journeyMetric($historical, 'total_stops'),
                    'invoices' => $this->journeyMetric($proposed, 'total_invoices') - $this->journeyMetric($historical, 'total_invoices'),
                    'distance_meters' => $this->journeyMetric($proposed, 'distance_meters') - $this->journeyMetric($historical, 'distance_meters'),
                    'duration_seconds' => $this->journeyMetric($proposed, 'duration_seconds') - $this->journeyMetric($historical, 'duration_seconds'),
                ],
            ];
        }, $driverKeys);

        usort($rows, function (array $left, array $right): int {
            $rightVolume = max($right['historical']['total_invoices'], $right['proposed']['total_invoices']);
            $leftVolume = max($left['historical']['total_invoices'], $left['proposed']['total_invoices']);

            if ($leftVolume !== $rightVolume) {
                return $rightVolume <=> $leftVolume;
            }

            return strcmp((string) $left['driver']['name'], (string) $right['driver']['name']);
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $historicalJourneys
     * @param  list<array<string, mixed>>  $proposedJourneys
     * @param  list<array<string, mixed>>  $unassignedStops
     * @param  list<array<string, mixed>>  $outsideBogotaStops
     * @param  list<array<string, mixed>>  $dataQualityExcludedStops
     * @param  array<string, array<string, mixed>>  $historicalDistribution
     * @return array<string, mixed>
     */
    private function buildSummary(
        array $historicalJourneys,
        array $proposedJourneys,
        array $unassignedStops,
        array $outsideBogotaStops,
        array $dataQualityExcludedStops,
        array $historicalDistribution,
    ): array {
        $historical = $this->aggregateJourneyMetrics($historicalJourneys);
        $proposed = $this->aggregateJourneyMetrics($proposedJourneys);

        return [
            'historical' => $historical,
            'proposed' => $proposed,
            'delta' => [
                'distance_meters' => $proposed['distance_meters'] - $historical['distance_meters'],
                'duration_seconds' => $proposed['duration_seconds'] - $historical['duration_seconds'],
            ],
            'outside_bogota_stops' => count($outsideBogotaStops),
            'outside_bogota_invoices' => array_sum(array_column($outsideBogotaStops, 'invoice_count')),
            'data_quality_excluded_stops' => count($dataQualityExcludedStops),
            'data_quality_excluded_invoices' => array_sum(array_column($dataQualityExcludedStops, 'invoice_count')),
            'unassigned_stops' => count($unassignedStops),
            'unassigned_invoices' => array_sum(array_column($unassignedStops, 'invoice_count')),
            'redistributed_stops' => $this->countRedistributedStops($proposedJourneys, $historicalDistribution),
            'reassigned_invoices' => $this->countReassignedInvoices($proposedJourneys, $historicalDistribution),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $journeys
     * @return array<string, float|int>
     */
    private function aggregateJourneyMetrics(array $journeys): array
    {
        return [
            'driver_count' => count($journeys),
            'total_stops' => array_sum(array_map(fn (array $journey): int => (int) $journey['total_stops'], $journeys)),
            'total_invoices' => array_sum(array_map(fn (array $journey): int => (int) $journey['total_invoices'], $journeys)),
            'distance_meters' => array_sum(array_map(
                fn (array $journey): float => (float) data_get($journey, 'route_preview.metrics.distance_meters', 0),
                $journeys,
            )),
            'duration_seconds' => array_sum(array_map(
                fn (array $journey): float => (float) data_get($journey, 'route_preview.metrics.duration_seconds', 0),
                $journeys,
            )),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $proposedJourneys
     * @param  array<string, array<string, mixed>>  $historicalDistribution
     */
    private function countRedistributedStops(array $proposedJourneys, array $historicalDistribution): int
    {
        $total = 0;

        foreach ($proposedJourneys as $journey) {
            $proposedDriverId = $journey['driver']['id'] ?? null;
            if ($proposedDriverId === null) {
                continue;
            }

            foreach ($journey['stops'] as $stop) {
                $historicalDriverIds = array_keys($historicalDistribution[$stop['stop_key']]['by_driver_id'] ?? []);
                sort($historicalDriverIds);

                if ($historicalDriverIds === [] || count($historicalDriverIds) !== 1 || (int) $historicalDriverIds[0] !== (int) $proposedDriverId) {
                    $total++;
                }
            }
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $proposedJourneys
     * @param  array<string, array<string, mixed>>  $historicalDistribution
     */
    private function countReassignedInvoices(array $proposedJourneys, array $historicalDistribution): int
    {
        $total = 0;

        foreach ($proposedJourneys as $journey) {
            $proposedDriverId = $journey['driver']['id'] ?? null;
            if ($proposedDriverId === null) {
                continue;
            }

            foreach ($journey['stops'] as $stop) {
                $historicalInvoicesForDriver = (int) ($historicalDistribution[$stop['stop_key']]['by_driver_id'][$proposedDriverId] ?? 0);
                $total += max(0, (int) $stop['invoice_count'] - $historicalInvoicesForDriver);
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>|null  $journey
     * @return array<string, float|int|bool>
     */
    private function journeySummaryPayload(?array $journey): array
    {
        return [
            'present' => $journey !== null,
            'total_stops' => $this->journeyMetric($journey, 'total_stops'),
            'total_invoices' => $this->journeyMetric($journey, 'total_invoices'),
            'distance_meters' => $this->journeyMetric($journey, 'distance_meters'),
            'duration_seconds' => $this->journeyMetric($journey, 'duration_seconds'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $journey
     */
    private function journeyMetric(?array $journey, string $key): int|float
    {
        if ($journey === null) {
            return 0;
        }

        if ($key === 'distance_meters' || $key === 'duration_seconds') {
            return (float) data_get($journey, 'route_preview.metrics.'.$key, 0);
        }

        return (int) ($journey[$key] ?? 0);
    }

    /**
     * @param  array<string, mixed>|null  $historical
     * @param  array<string, mixed>|null  $proposed
     */
    private function driverComparisonStatus(?array $historical, ?array $proposed): string
    {
        return match (true) {
            $historical !== null && $proposed !== null => 'compared',
            $historical !== null => 'historical_only',
            $proposed !== null => 'proposed_only',
            default => 'no_data',
        };
    }

    /**
     * @return array{id: int|null, name: string, external_id: string|null, is_active: bool}
     */
    private function driverPayload(?Driver $driver): array
    {
        return [
            'id' => $driver?->id,
            'name' => $driver?->name ?? 'Sin conductor',
            'external_id' => $driver?->external_id,
            'is_active' => (bool) ($driver?->is_active ?? false),
        ];
    }

    private function driverKey(?Driver $driver): string
    {
        if ($driver?->id !== null) {
            return 'driver:'.$driver->id;
        }

        return 'driver:missing';
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
}
