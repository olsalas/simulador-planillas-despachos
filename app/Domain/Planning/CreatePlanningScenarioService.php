<?php

namespace App\Domain\Planning;

use App\Models\Depot;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\PlanningScenario;
use App\Models\RouteBatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreatePlanningScenarioService
{
    public function createOrRefresh(User $user, Depot $depot, string $serviceDate): PlanningScenario
    {
        return DB::transaction(function () use ($user, $depot, $serviceDate): PlanningScenario {
            $scenario = PlanningScenario::query()->firstOrNew([
                'depot_id' => $depot->id,
                'service_date' => $serviceDate,
            ]);

            if (! $scenario->exists) {
                $scenario->created_by = $user->id;
            }

            $scenario->fill([
                'name' => sprintf('%s | %s', $serviceDate, $depot->name),
                'status' => 'draft',
                'configuration' => $this->defaultConfiguration(),
                'summary' => [],
                'last_generated_at' => now(),
            ]);
            $scenario->save();

            $snapshot = $this->buildSnapshot($depot, $serviceDate);

            $scenario->stops()->delete();
            $scenario->stops()->createMany($snapshot['stops']);

            $scenario->forceFill([
                'status' => $snapshot['summary']['total_invoices'] > 0 ? 'snapshot_ready' : 'empty',
                'summary' => $snapshot['summary'],
                'last_generated_at' => now(),
            ])->save();

            return $scenario->fresh(['depot', 'creator']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultConfiguration(): array
    {
        return [
            'return_to_depot' => true,
            'prioritize_proximity' => true,
            'respect_zones' => false,
            'allow_cross_zone_assignment' => true,
            'max_stops_per_driver' => null,
            'max_invoices_per_journey' => null,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, stops: list<array<string, mixed>>}
     */
    private function buildSnapshot(Depot $depot, string $serviceDate): array
    {
        $candidateInvoices = Invoice::query()
            ->with([
                'branch:id,code,name,address,latitude,longitude',
                'driver:id,name,external_id,depot_id,is_active',
            ])
            ->whereDate('service_date', $serviceDate)
            ->whereHas('driver', fn ($query) => $query->where('depot_id', $depot->id))
            ->orderBy('id')
            ->get();

        $activeDrivers = Driver::query()
            ->where('depot_id', $depot->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'external_id']);

        $allDepotDrivers = Driver::query()
            ->where('depot_id', $depot->id)
            ->count();

        $historicalBatchCount = RouteBatch::query()
            ->whereDate('service_date', $serviceDate)
            ->whereHas('driver', fn ($query) => $query->where('depot_id', $depot->id))
            ->count();

        $stops = [];

        $groupedByBranch = $candidateInvoices
            ->filter(fn (Invoice $invoice): bool => $invoice->branch_id !== null && $invoice->branch !== null)
            ->groupBy('branch_id');

        foreach ($groupedByBranch as $branchInvoices) {
            /** @var Collection<int, Invoice> $branchInvoices */
            $branch = $branchInvoices->first()?->branch;

            if ($branch === null) {
                continue;
            }

            $isExcluded = $branch->latitude === null || $branch->longitude === null;

            $stops[] = [
                'stop_key' => 'branch:'.$branch->id,
                'branch_id' => $branch->id,
                'status' => $isExcluded ? 'excluded' : 'pending_assignment',
                'exclusion_reason' => $isExcluded ? 'missing_branch_geocode' : null,
                'branch_code' => $branch->code,
                'branch_name' => $branch->name,
                'branch_address' => $branch->address,
                'latitude' => $branch->latitude,
                'longitude' => $branch->longitude,
                'invoice_count' => $branchInvoices->count(),
                'historical_sequence_min' => $branchInvoices
                    ->pluck('historical_sequence')
                    ->filter(fn ($value): bool => $value !== null)
                    ->min(),
                'invoice_ids' => $branchInvoices->pluck('id')->all(),
                'metadata' => [
                    'source_driver_ids' => $branchInvoices->pluck('driver_id')->unique()->values()->all(),
                    'source_driver_external_ids' => $branchInvoices
                        ->pluck('driver.external_id')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ],
            ];
        }

        foreach ($candidateInvoices as $invoice) {
            if ($invoice->branch_id !== null && $invoice->branch !== null) {
                continue;
            }

            $stops[] = [
                'stop_key' => 'invoice:'.$invoice->id,
                'branch_id' => null,
                'status' => 'excluded',
                'exclusion_reason' => (string) ($invoice->outlier_reason ?: 'missing_branch'),
                'branch_code' => null,
                'branch_name' => 'Factura sin sucursal consolidable',
                'branch_address' => null,
                'latitude' => null,
                'longitude' => null,
                'invoice_count' => 1,
                'historical_sequence_min' => $invoice->historical_sequence,
                'invoice_ids' => [$invoice->id],
                'metadata' => [
                    'source_driver_ids' => [$invoice->driver_id],
                    'source_driver_external_ids' => array_values(array_filter([$invoice->driver?->external_id])),
                ],
            ];
        }

        usort($stops, function (array $left, array $right): int {
            $statusCompare = strcmp((string) $left['status'], (string) $right['status']);
            if ($statusCompare !== 0) {
                return $statusCompare;
            }

            $invoiceCountCompare = ((int) $right['invoice_count']) <=> ((int) $left['invoice_count']);
            if ($invoiceCountCompare !== 0) {
                return $invoiceCountCompare;
            }

            return strcmp((string) $left['stop_key'], (string) $right['stop_key']);
        });

        $eligibleStops = array_values(array_filter(
            $stops,
            fn (array $stop): bool => $stop['status'] === 'pending_assignment'
        ));

        $excludedStops = array_values(array_filter(
            $stops,
            fn (array $stop): bool => $stop['status'] === 'excluded'
        ));

        return [
            'summary' => [
                'total_invoices' => $candidateInvoices->count(),
                'eligible_invoices' => array_sum(array_column($eligibleStops, 'invoice_count')),
                'excluded_invoices' => array_sum(array_column($excludedStops, 'invoice_count')),
                'total_stops' => count($stops),
                'eligible_stops' => count($eligibleStops),
                'excluded_stops' => count($excludedStops),
                'active_drivers_in_depot' => $activeDrivers->count(),
                'total_drivers_in_depot' => $allDepotDrivers,
                'historical_driver_count' => $candidateInvoices->pluck('driver_id')->filter()->unique()->count(),
                'historical_route_batches' => $historicalBatchCount,
            ],
            'stops' => $stops,
        ];
    }
}
