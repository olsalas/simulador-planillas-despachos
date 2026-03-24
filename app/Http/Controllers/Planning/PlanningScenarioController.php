<?php

namespace App\Http\Controllers\Planning;

use App\Domain\Planning\BogotaScope;
use App\Domain\Planning\CreatePlanningScenarioService;
use App\Domain\Planning\GeneratePlanningScenarioAllocationService;
use App\Domain\Simulation\BuildSimulationRouteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\StorePlanningScenarioRequest;
use App\Models\Depot;
use App\Models\PlanningScenario;
use App\Models\PlanningScenarioJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PlanningScenarioController extends Controller
{
    public function index(BogotaScope $bogotaScope): Response
    {
        $depots = Depot::query()
            ->where('is_active', true)
            ->withCount([
                'drivers as active_drivers_count' => fn ($query) => $query->where('is_active', true),
                'drivers as total_drivers_count',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Depot $depot) => [
                'id' => $depot->id,
                'code' => $depot->code,
                'name' => $depot->name,
                'address' => $depot->address,
                'active_drivers_count' => (int) $depot->active_drivers_count,
                'total_drivers_count' => (int) $depot->total_drivers_count,
                'is_bogota' => $bogotaScope->isBogotaDepot($depot),
            ])
            ->values();

        $scenarios = PlanningScenario::query()
            ->with('depot:id,code,name')
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (PlanningScenario $scenario) => [
                'id' => $scenario->id,
                'name' => $scenario->name,
                'service_date' => $scenario->service_date->toDateString(),
                'status' => $scenario->status,
                'depot' => [
                    'id' => $scenario->depot?->id,
                    'code' => $scenario->depot?->code,
                    'name' => $scenario->depot?->name,
                ],
                'is_bogota' => $scenario->depot !== null ? $bogotaScope->isBogotaDepot($scenario->depot) : false,
                'summary' => $scenario->summary ?? [],
                'last_generated_at' => $scenario->last_generated_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Planning/Index', [
            'depots' => $depots,
            'scenarios' => $scenarios,
            'defaultServiceDate' => now()->toDateString(),
        ]);
    }

    public function store(
        StorePlanningScenarioRequest $request,
        CreatePlanningScenarioService $createPlanningScenarioService,
    ): RedirectResponse {
        $depot = Depot::query()->findOrFail($request->integer('depot_id'));

        $scenario = $createPlanningScenarioService->createOrRefresh(
            $request->user(),
            $depot,
            $request->string('service_date')->toString(),
        );

        return redirect()
            ->route('planning.scenarios.show', $scenario)
            ->with('success', 'Escenario de planillado generado y actualizado con el snapshot operativo del día.');
    }

    public function show(
        PlanningScenario $planningScenario,
        BuildSimulationRouteService $buildSimulationRouteService,
        BogotaScope $bogotaScope,
    ): Response
    {
        $planningScenario->load(['depot:id,code,name,address,latitude,longitude', 'creator:id,name,email']);

        $candidateStops = $planningScenario->stops()
            ->whereIn('status', ['pending_assignment', 'assigned', 'unassigned'])
            ->orderByDesc('invoice_count')
            ->orderBy('branch_name')
            ->get()
            ->map(fn ($stop) => [
                'id' => $stop->id,
                'stop_key' => $stop->stop_key,
                'branch_code' => $stop->branch_code,
                'branch_name' => $stop->branch_name,
                'branch_address' => $stop->branch_address,
                'invoice_count' => $stop->invoice_count,
                'historical_sequence_min' => $stop->historical_sequence_min,
                'latitude' => $stop->latitude,
                'longitude' => $stop->longitude,
                'status' => $stop->status,
                'suggested_sequence' => $stop->suggested_sequence,
                'assignment_reason' => $stop->assignment_reason,
            ])
            ->values();

        $excludedStops = $planningScenario->stops()
            ->where('status', 'excluded')
            ->orderByDesc('invoice_count')
            ->orderBy('stop_key')
            ->get()
            ->map(fn ($stop) => [
                'id' => $stop->id,
                'stop_key' => $stop->stop_key,
                'branch_code' => $stop->branch_code,
                'branch_name' => $stop->branch_name,
                'branch_address' => $stop->branch_address,
                'invoice_count' => $stop->invoice_count,
                'reason' => $stop->exclusion_reason,
            ])
            ->values();

        $unassignedStops = $planningScenario->stops()
            ->where('status', 'unassigned')
            ->orderByDesc('invoice_count')
            ->orderBy('branch_name')
            ->get()
            ->map(fn ($stop) => [
                'id' => $stop->id,
                'stop_key' => $stop->stop_key,
                'branch_code' => $stop->branch_code,
                'branch_name' => $stop->branch_name,
                'branch_address' => $stop->branch_address,
                'invoice_count' => $stop->invoice_count,
                'reason' => $stop->assignment_reason,
            ])
            ->values();

        $drivers = $planningScenario->depot
            ? $planningScenario->depot->drivers()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'external_id', 'is_active'])
                ->map(fn ($driver) => [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'external_id' => $driver->external_id,
                    'is_active' => (bool) $driver->is_active,
                ])
                ->values()
            : collect();

        $proposedJourneys = $planningScenario->journeys()
            ->with(['driver:id,name,external_id', 'stops'])
            ->orderBy('id')
            ->get()
            ->map(function (PlanningScenarioJourney $journey) use ($planningScenario, $buildSimulationRouteService) {
                $orderedStops = $journey->stops
                    ->sortBy('suggested_sequence')
                    ->values();

                return [
                    'id' => $journey->id,
                    'name' => $journey->name,
                    'status' => $journey->status,
                    'total_stops' => $journey->total_stops,
                    'total_invoices' => $journey->total_invoices,
                    'summary' => $journey->summary ?? [],
                    'route_preview' => $this->journeyRoutePreview(
                        $planningScenario,
                        $journey,
                        $orderedStops,
                        $buildSimulationRouteService,
                    ),
                    'driver' => [
                        'id' => $journey->driver?->id,
                        'name' => $journey->driver?->name,
                        'external_id' => $journey->driver?->external_id,
                    ],
                    'stops' => $orderedStops
                        ->map(fn ($stop) => [
                            'id' => $stop->id,
                            'branch_code' => $stop->branch_code,
                            'branch_name' => $stop->branch_name,
                            'branch_address' => $stop->branch_address,
                            'invoice_count' => $stop->invoice_count,
                            'suggested_sequence' => $stop->suggested_sequence,
                            'historical_sequence_min' => $stop->historical_sequence_min,
                            'latitude' => $stop->latitude !== null ? (float) $stop->latitude : null,
                            'longitude' => $stop->longitude !== null ? (float) $stop->longitude : null,
                        ])
                        ->all(),
                ];
            })
            ->values();

        return Inertia::render('Planning/Show', [
            'scenario' => [
                'id' => $planningScenario->id,
                'name' => $planningScenario->name,
                'service_date' => $planningScenario->service_date->toDateString(),
                'status' => $planningScenario->status,
                'configuration' => $planningScenario->configuration ?? [],
                'summary' => $planningScenario->summary ?? [],
                'last_generated_at' => $planningScenario->last_generated_at?->toIso8601String(),
                'is_bogota' => $planningScenario->depot !== null ? $bogotaScope->isBogotaDepot($planningScenario->depot) : false,
                'depot' => [
                    'id' => $planningScenario->depot?->id,
                    'code' => $planningScenario->depot?->code,
                    'name' => $planningScenario->depot?->name,
                    'address' => $planningScenario->depot?->address,
                ],
                'creator' => [
                    'id' => $planningScenario->creator?->id,
                    'name' => $planningScenario->creator?->name,
                    'email' => $planningScenario->creator?->email,
                ],
            ],
            'candidateStops' => $candidateStops,
            'excludedStops' => $excludedStops,
            'unassignedStops' => $unassignedStops,
            'drivers' => $drivers,
            'proposedJourneys' => $proposedJourneys,
        ]);
    }

    public function allocate(
        PlanningScenario $planningScenario,
        GeneratePlanningScenarioAllocationService $generatePlanningScenarioAllocationService,
    ): RedirectResponse {
        $generatePlanningScenarioAllocationService->generate($planningScenario);

        return redirect()
            ->route('planning.scenarios.show', $planningScenario)
            ->with('success', 'Se generó una propuesta base de asignación y secuenciación para el escenario.');
    }

    /**
     * @return array<string, mixed>
     */
    private function journeyRoutePreview(
        PlanningScenario $planningScenario,
        PlanningScenarioJourney $journey,
        Collection $orderedStops,
        BuildSimulationRouteService $buildSimulationRouteService,
    ): array {
        $summary = $journey->summary ?? [];

        $preview = [
            'provider' => data_get($summary, 'provider'),
            'cache_hit' => (bool) data_get($summary, 'cache_hit', false),
            'return_to_depot' => (bool) data_get($summary, 'return_to_depot', true),
            'depot' => data_get($summary, 'depot'),
            'geometry' => data_get($summary, 'geometry', []),
            'bounds' => data_get($summary, 'bounds'),
        ];

        if ($preview['depot'] !== null && $preview['geometry'] !== []) {
            return $preview;
        }

        $depot = $this->scenarioDepotPayload($planningScenario);
        if ($depot === null) {
            return $preview;
        }

        $routeStops = $orderedStops
            ->filter(fn ($stop) => $stop->latitude !== null && $stop->longitude !== null)
            ->map(fn ($stop) => [
                'planning_scenario_stop_id' => $stop->id,
                'branch_id' => $stop->branch_id,
                'branch_code' => $stop->branch_code,
                'branch_name' => $stop->branch_name,
                'branch_address' => $stop->branch_address,
                'invoice_count' => (int) $stop->invoice_count,
                'historical_sequence' => $stop->historical_sequence_min,
                'lat' => (float) $stop->latitude,
                'lng' => (float) $stop->longitude,
            ])
            ->values()
            ->all();

        if ($routeStops === []) {
            return $preview;
        }

        $fallbackPreview = $buildSimulationRouteService->buildPreviewFromDepot(
            $depot,
            $routeStops,
            $preview['return_to_depot'],
            metadata: [
                'label' => 'Propuesta base',
                'source' => 'planning_show_fallback',
            ],
        );

        return [
            'provider' => $fallbackPreview['provider'],
            'cache_hit' => $fallbackPreview['cache_hit'],
            'return_to_depot' => $fallbackPreview['return_to_depot'],
            'depot' => $fallbackPreview['depot'],
            'geometry' => $fallbackPreview['geometry'],
            'bounds' => $fallbackPreview['bounds'],
        ];
    }

    /**
     * @return array{lat: float, lng: float, name: string, code: string|null, address: string|null, source: string}|null
     */
    private function scenarioDepotPayload(PlanningScenario $planningScenario): ?array
    {
        $depot = $planningScenario->depot;
        if ($depot === null || $depot->latitude === null || $depot->longitude === null) {
            return null;
        }

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
