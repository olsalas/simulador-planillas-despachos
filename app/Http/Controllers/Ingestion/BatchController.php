<?php

namespace App\Http\Controllers\Ingestion;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\InvoiceStop;
use App\Models\RouteBatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BatchController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'service_date' => $request->string('service_date')->toString(),
            'driver_id' => $request->string('driver_id')->toString(),
        ];

        $batches = RouteBatch::query()
            ->with('driver:id,name,external_id')
            ->when($filters['service_date'] !== '', function ($query) use ($filters) {
                $query->whereDate('service_date', $filters['service_date']);
            })
            ->when($filters['driver_id'] !== '', function ($query) use ($filters) {
                $query->where('driver_id', (int) $filters['driver_id']);
            })
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (RouteBatch $batch) => [
                'id' => $batch->id,
                'service_date' => $batch->service_date->toDateString(),
                'driver' => [
                    'id' => $batch->driver?->id,
                    'name' => $batch->driver?->name,
                    'external_id' => $batch->driver?->external_id,
                ],
                'total_invoices' => $batch->total_invoices,
                'total_stops' => $batch->total_stops,
                'pending_invoices' => $batch->pending_invoices,
                'status' => $batch->status,
            ]);

        $drivers = Driver::query()
            ->orderBy('name')
            ->get(['id', 'name', 'external_id']);

        return Inertia::render('Ingestion/Batches', [
            'filters' => $filters,
            'batches' => $batches,
            'drivers' => $drivers,
        ]);
    }

    public function show(RouteBatch $routeBatch): Response
    {
        $routeBatch->load('driver:id,name,external_id');

        $serviceDate = $routeBatch->service_date->toDateString();

        $stops = InvoiceStop::query()
            ->with('branch:id,code,name,address,latitude,longitude')
            ->where('driver_id', $routeBatch->driver_id)
            ->whereDate('service_date', $serviceDate)
            ->orderByDesc('invoice_count')
            ->get();

        $pendingInvoices = Invoice::query()
            ->with('branch:id,code,name')
            ->where('driver_id', $routeBatch->driver_id)
            ->whereDate('service_date', $serviceDate)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get(['id', 'external_invoice_id', 'invoice_number', 'outlier_reason', 'branch_id']);

        return Inertia::render('Ingestion/BatchShow', [
            'batch' => [
                'id' => $routeBatch->id,
                'service_date' => $serviceDate,
                'driver' => [
                    'id' => $routeBatch->driver?->id,
                    'name' => $routeBatch->driver?->name,
                    'external_id' => $routeBatch->driver?->external_id,
                ],
                'total_invoices' => $routeBatch->total_invoices,
                'total_stops' => $routeBatch->total_stops,
                'pending_invoices' => $routeBatch->pending_invoices,
                'status' => $routeBatch->status,
            ],
            'stops' => $stops,
            'pendingInvoices' => $pendingInvoices,
        ]);
    }
}
