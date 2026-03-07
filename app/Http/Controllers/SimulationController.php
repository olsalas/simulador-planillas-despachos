<?php

namespace App\Http\Controllers;

use App\Contracts\RoutingProvider;
use App\Domain\Simulation\BuildJourneyComparisonService;
use App\Domain\Simulation\BuildSimulationRouteService;
use App\Http\Requests\Simulation\CompareJourneyRequest;
use App\Http\Requests\Simulation\PreviewRouteRequest;
use App\Models\RouteBatch;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class SimulationController extends Controller
{
    public function index(RoutingProvider $routingProvider): Response
    {
        $batches = RouteBatch::query()
            ->with('driver:id,name,external_id')
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (RouteBatch $batch) => [
                'id' => $batch->id,
                'label' => sprintf(
                    '%s | %s | %s',
                    $batch->service_date->toDateString(),
                    $batch->driver?->name ?? 'Sin conductor',
                    $batch->driver?->external_id ?? '-'
                ),
                'service_date' => $batch->service_date->toDateString(),
                'driver_name' => $batch->driver?->name,
                'driver_external_id' => $batch->driver?->external_id,
                'total_stops' => $batch->total_stops,
                'pending_invoices' => $batch->pending_invoices,
            ])
            ->values();

        return Inertia::render('Simulation/Run', [
            'batches' => $batches,
            'defaultBatchId' => null,
            'routing' => [
                'configured_provider' => config('routing.provider'),
                'effective_provider' => $routingProvider->name(),
                'here_enabled' => filled(config('services.here.api_key')),
            ],
        ]);
    }

    public function preview(
        PreviewRouteRequest $request,
        BuildSimulationRouteService $buildSimulationRouteService
    ): JsonResponse {
        $routeBatch = RouteBatch::query()
            ->with('driver.depot')
            ->findOrFail($request->integer('route_batch_id'));

        $routePreview = $buildSimulationRouteService->buildForBatch(
            $routeBatch,
            $request->boolean('return_to_depot', true),
        );

        return response()->json($routePreview);
    }

    public function compare(
        CompareJourneyRequest $request,
        BuildJourneyComparisonService $buildJourneyComparisonService
    ): JsonResponse {
        $routeBatch = RouteBatch::query()
            ->with('driver.depot')
            ->findOrFail($request->integer('route_batch_id'));

        $comparison = $buildJourneyComparisonService->buildForBatch(
            $routeBatch,
            $request->boolean('return_to_depot', true),
        );

        return response()->json($comparison);
    }
}
