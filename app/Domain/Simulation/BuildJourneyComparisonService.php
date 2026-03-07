<?php

namespace App\Domain\Simulation;

use App\Contracts\RoutingProvider;
use App\Domain\Routing\Providers\MockRoutingProvider;
use App\Models\Invoice;
use App\Models\RouteBatch;
use Illuminate\Support\Collection;
use Throwable;

class BuildJourneyComparisonService
{
    public function __construct(
        private readonly BuildSimulationRouteService $buildSimulationRouteService,
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

        $stops = $this->classifyJourneyStops($routeBatch);
        $comparableStops = $stops['comparable_stops'];
        $nonComparableStops = $stops['non_comparable_stops'];
        $excludedStops = $stops['excluded_stops'];

        $historicalStops = $this->orderHistoricalStops($comparableStops);
        $suggestedStops = $this->orderSuggestedStops($routeBatch, $comparableStops);

        $historicalRoute = $this->buildSimulationRouteService->buildPreviewForOrderedStops(
            $routeBatch,
            $historicalStops,
            $returnToDepot,
            metadata: [
                'label' => 'Como fue',
                'source' => 'historical',
            ],
        );

        $suggestedRoute = $this->buildSimulationRouteService->buildPreviewForOrderedStops(
            $routeBatch,
            $suggestedStops,
            $returnToDepot,
            metadata: [
                'label' => 'Como pudo ser',
                'source' => 'suggested',
                'algorithm' => 'nearest_neighbor',
            ],
        );

        return [
            'journey' => [
                'route_batch_id' => $routeBatch->id,
                'service_date' => $routeBatch->service_date->toDateString(),
                'driver' => [
                    'id' => $routeBatch->driver?->id,
                    'name' => $routeBatch->driver?->name,
                    'external_id' => $routeBatch->driver?->external_id,
                ],
                'summary' => [
                    'total_invoices' => (int) $routeBatch->total_invoices,
                    'total_stops' => (int) $routeBatch->total_stops,
                    'comparable_stops' => count($comparableStops),
                    'non_comparable_stops' => count($nonComparableStops),
                    'excluded_stops' => count($excludedStops),
                ],
            ],
            'historical_route' => $historicalRoute,
            'suggested_route' => $suggestedRoute,
            'delta' => [
                'distance_meters' => (float) $suggestedRoute['metrics']['distance_meters']
                    - (float) $historicalRoute['metrics']['distance_meters'],
                'duration_seconds' => (float) $suggestedRoute['metrics']['duration_seconds']
                    - (float) $historicalRoute['metrics']['duration_seconds'],
            ],
            'non_comparable_stops' => $nonComparableStops,
            'excluded_stops' => $excludedStops,
        ];
    }

    /**
     * @return array{
     *     comparable_stops: list<array<string, mixed>>,
     *     non_comparable_stops: list<array<string, mixed>>,
     *     excluded_stops: list<array<string, mixed>>
     * }
     */
    private function classifyJourneyStops(RouteBatch $routeBatch): array
    {
        $invoices = Invoice::query()
            ->with('branch:id,code,name,address,latitude,longitude')
            ->where('driver_id', $routeBatch->driver_id)
            ->whereDate('service_date', $routeBatch->service_date->toDateString())
            ->orderBy('id')
            ->get();

        $groupedByBranch = $invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->branch_id !== null && $invoice->branch !== null)
            ->groupBy('branch_id');

        $comparableStops = [];
        $nonComparableStops = [];
        $excludedStops = [];

        foreach ($groupedByBranch as $branchInvoices) {
            /** @var Collection<int, Invoice> $branchInvoices */
            $branch = $branchInvoices->first()?->branch;

            if ($branch === null) {
                continue;
            }

            if ($branch->latitude === null || $branch->longitude === null) {
                $excludedStops[] = $this->buildExcludedBranchStop($branchInvoices, 'missing_branch_geocode');
                continue;
            }

            $historicalSequence = $branchInvoices
                ->pluck('historical_sequence')
                ->filter(fn ($value): bool => $value !== null)
                ->min();

            if ($historicalSequence === null) {
                $nonComparableStops[] = $this->buildBranchStop($branchInvoices, null, 'missing_historical_sequence');
                continue;
            }

            $comparableStops[] = $this->buildBranchStop($branchInvoices, (int) $historicalSequence);
        }

        foreach ($invoices->whereNull('branch_id') as $invoice) {
            $excludedStops[] = [
                'stop_key' => 'invoice:'.$invoice->id,
                'branch' => null,
                'reason' => (string) ($invoice->outlier_reason ?: 'missing_branch'),
                'invoice_count' => 1,
                'invoice_ids' => [$invoice->id],
            ];
        }

        usort($comparableStops, fn (array $left, array $right): int => $this->sortByBranchIdentity($left, $right));
        usort($nonComparableStops, fn (array $left, array $right): int => $this->sortByBranchIdentity($left, $right));
        usort($excludedStops, fn (array $left, array $right): int => strcmp((string) $left['stop_key'], (string) $right['stop_key']));

        return [
            'comparable_stops' => $comparableStops,
            'non_comparable_stops' => $nonComparableStops,
            'excluded_stops' => $excludedStops,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $comparableStops
     * @return list<array<string, mixed>>
     */
    private function orderHistoricalStops(array $comparableStops): array
    {
        usort($comparableStops, function (array $left, array $right): int {
            $sequenceCompare = ((int) $left['historical_sequence']) <=> ((int) $right['historical_sequence']);

            if ($sequenceCompare !== 0) {
                return $sequenceCompare;
            }

            return $this->sortByBranchIdentity($left, $right);
        });

        return array_values($comparableStops);
    }

    /**
     * @param  list<array<string, mixed>>  $comparableStops
     * @return list<array<string, mixed>>
     */
    private function orderSuggestedStops(RouteBatch $routeBatch, array $comparableStops): array
    {
        if ($comparableStops === []) {
            return [];
        }

        usort($comparableStops, fn (array $left, array $right): int => $this->sortByBranchIdentity($left, $right));
        $comparableStops = array_values($comparableStops);

        $depot = $this->buildSimulationRouteService->resolveDepotForStops($routeBatch, $comparableStops);

        $matrix = $this->buildMatrixWithFallback(array_merge([[
            'lat' => $depot['lat'],
            'lng' => $depot['lng'],
        ]], array_map(
            fn (array $stop): array => [
                'lat' => (float) $stop['lat'],
                'lng' => (float) $stop['lng'],
            ],
            $comparableStops
        )));

        /** @var list<list<float|null>> $durations */
        $durations = $matrix['durations'] ?? [];

        $remaining = range(1, count($comparableStops));
        $currentIndex = 0;
        $ordered = [];

        while ($remaining !== []) {
            $bestCandidate = null;
            $bestDuration = INF;

            foreach ($remaining as $candidateIndex) {
                $candidateDuration = $durations[$currentIndex][$candidateIndex] ?? INF;
                $candidateStop = $comparableStops[$candidateIndex - 1];

                if ($candidateDuration < $bestDuration) {
                    $bestDuration = $candidateDuration;
                    $bestCandidate = $candidateIndex;
                    continue;
                }

                if ($candidateDuration === $bestDuration && $bestCandidate !== null) {
                    $currentBestStop = $comparableStops[$bestCandidate - 1];
                    if ($this->sortByBranchIdentity($candidateStop, $currentBestStop) < 0) {
                        $bestCandidate = $candidateIndex;
                    }
                }
            }

            if ($bestCandidate === null) {
                break;
            }

            $ordered[] = $comparableStops[$bestCandidate - 1];
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
     * @param  Collection<int, Invoice>  $branchInvoices
     * @return array<string, mixed>
     */
    private function buildBranchStop(Collection $branchInvoices, ?int $historicalSequence, ?string $reason = null): array
    {
        $branch = $branchInvoices->firstOrFail()->branch;

        return [
            'stop_key' => 'branch:'.$branch->id,
            'branch_id' => $branch->id,
            'branch_code' => $branch->code,
            'branch_name' => $branch->name,
            'branch_address' => $branch->address,
            'invoice_count' => $branchInvoices->count(),
            'invoice_ids' => $branchInvoices->pluck('id')->all(),
            'historical_sequence' => $historicalSequence,
            'lat' => (float) $branch->latitude,
            'lng' => (float) $branch->longitude,
            'reason' => $reason,
        ];
    }

    /**
     * @param  Collection<int, Invoice>  $branchInvoices
     * @return array<string, mixed>
     */
    private function buildExcludedBranchStop(Collection $branchInvoices, string $reason): array
    {
        $branch = $branchInvoices->firstOrFail()->branch;

        return [
            'stop_key' => 'branch:'.$branch->id,
            'branch' => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'address' => $branch->address,
            ],
            'reason' => $reason,
            'invoice_count' => $branchInvoices->count(),
            'invoice_ids' => $branchInvoices->pluck('id')->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sortByBranchIdentity(array $left, array $right): int
    {
        $leftCode = (string) ($left['branch_code'] ?? '');
        $rightCode = (string) ($right['branch_code'] ?? '');
        $codeCompare = strcmp($leftCode, $rightCode);

        if ($codeCompare !== 0) {
            return $codeCompare;
        }

        return ((int) ($left['branch_id'] ?? 0)) <=> ((int) ($right['branch_id'] ?? 0));
    }
}
