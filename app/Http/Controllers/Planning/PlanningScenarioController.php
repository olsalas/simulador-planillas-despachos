<?php

namespace App\Http\Controllers\Planning;

use App\Domain\Planning\CreatePlanningScenarioService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\StorePlanningScenarioRequest;
use App\Models\Depot;
use App\Models\PlanningScenario;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PlanningScenarioController extends Controller
{
    public function index(): Response
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

    public function show(PlanningScenario $planningScenario): Response
    {
        $planningScenario->load(['depot:id,code,name,address', 'creator:id,name,email']);

        $eligibleStops = $planningScenario->stops()
            ->where('status', 'pending_assignment')
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

        return Inertia::render('Planning/Show', [
            'scenario' => [
                'id' => $planningScenario->id,
                'name' => $planningScenario->name,
                'service_date' => $planningScenario->service_date->toDateString(),
                'status' => $planningScenario->status,
                'configuration' => $planningScenario->configuration ?? [],
                'summary' => $planningScenario->summary ?? [],
                'last_generated_at' => $planningScenario->last_generated_at?->toIso8601String(),
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
            'eligibleStops' => $eligibleStops,
            'excludedStops' => $excludedStops,
            'drivers' => $drivers,
        ]);
    }
}
