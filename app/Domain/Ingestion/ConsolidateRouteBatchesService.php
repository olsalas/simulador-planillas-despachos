<?php

namespace App\Domain\Ingestion;

use App\Models\Invoice;
use App\Models\InvoiceStop;
use App\Models\RouteBatch;

class ConsolidateRouteBatchesService
{
    /**
     * @param  list<string>  $affectedKeys
     * @return list<int>
     */
    public function consolidate(array $affectedKeys, ?int $sourceIngestionBatchId = null): array
    {
        $routeBatchIds = [];

        foreach ($this->normalizeAffectedKeys($affectedKeys) as $affectedKey) {
            [$driverId, $serviceDate] = explode('|', $affectedKey, 2);

            $invoices = Invoice::query()
                ->where('driver_id', (int) $driverId)
                ->whereDate('service_date', $serviceDate)
                ->get();

            InvoiceStop::query()
                ->where('driver_id', (int) $driverId)
                ->whereDate('service_date', $serviceDate)
                ->delete();

            if ($invoices->isEmpty()) {
                RouteBatch::query()
                    ->where('driver_id', (int) $driverId)
                    ->whereDate('service_date', $serviceDate)
                    ->delete();

                continue;
            }

            $totalInvoices = $invoices->count();
            $pendingInvoices = $invoices->where('status', 'pending')->count();
            $groupedStops = $invoices->whereNotNull('branch_id')->groupBy('branch_id');
            $totalStops = $groupedStops->count();

            $attributes = [
                'total_invoices' => $totalInvoices,
                'total_stops' => $totalStops,
                'pending_invoices' => $pendingInvoices,
                'status' => $pendingInvoices > 0 ? 'partial' : 'ready',
            ];

            if ($sourceIngestionBatchId !== null) {
                $attributes['source_ingestion_batch_id'] = $sourceIngestionBatchId;
            }

            $routeBatch = RouteBatch::updateOrCreate(
                [
                    'driver_id' => (int) $driverId,
                    'service_date' => $serviceDate,
                ],
                $attributes,
            );

            $routeBatchIds[] = $routeBatch->id;

            foreach ($groupedStops as $branchId => $group) {
                $pendingAtStop = $group->where('status', 'pending')->count();

                InvoiceStop::create([
                    'driver_id' => (int) $driverId,
                    'branch_id' => (int) $branchId,
                    'service_date' => $serviceDate,
                    'invoice_count' => $group->count(),
                    'planned_sequence' => null,
                    'status' => $pendingAtStop > 0 ? 'partial' : 'ready',
                    'distance_from_previous_meters' => null,
                    'travel_time_from_previous_seconds' => null,
                ]);
            }
        }

        return array_values(array_unique($routeBatchIds));
    }

    /**
     * @return list<int>
     */
    public function rebuildAll(): array
    {
        $affectedKeys = Invoice::query()
            ->whereNotNull('driver_id')
            ->select(['driver_id', 'service_date'])
            ->distinct()
            ->get()
            ->map(fn (Invoice $invoice): string => $invoice->driver_id.'|'.$invoice->service_date->toDateString())
            ->all();

        return $this->consolidate($affectedKeys);
    }

    /**
     * @param  list<string>  $affectedKeys
     * @return list<string>
     */
    private function normalizeAffectedKeys(array $affectedKeys): array
    {
        return array_values(array_unique(array_filter($affectedKeys, function (mixed $affectedKey): bool {
            if (! is_string($affectedKey) || $affectedKey === '') {
                return false;
            }

            return count(explode('|', $affectedKey, 2)) === 2;
        })));
    }
}
