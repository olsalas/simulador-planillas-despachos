<?php

namespace App\Http\Controllers\Planning;

use App\Domain\Planning\BogotaScope;
use App\Domain\Planning\BuildBogotaPlanningComparisonService;
use App\Http\Controllers\Controller;
use App\Models\PlanningScenario;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PlanningScenarioComparisonController extends Controller
{
    public function show(
        PlanningScenario $planningScenario,
        BuildBogotaPlanningComparisonService $buildBogotaPlanningComparisonService,
        BogotaScope $bogotaScope,
    ): InertiaResponse {
        $planningScenario->loadMissing('depot:id,code,name,address,latitude,longitude', 'creator:id,name,email');

        abort_unless(
            $planningScenario->depot !== null && $bogotaScope->isBogotaDepot($planningScenario->depot),
            Response::HTTP_NOT_FOUND,
        );

        return Inertia::render('Planning/Comparison', [
            'scenario' => [
                'id' => $planningScenario->id,
                'name' => $planningScenario->name,
                'service_date' => $planningScenario->service_date->toDateString(),
                'status' => $planningScenario->status,
                'summary' => $planningScenario->summary ?? [],
                'configuration' => $planningScenario->configuration ?? [],
                'is_bogota' => true,
                'creator' => [
                    'id' => $planningScenario->creator?->id,
                    'name' => $planningScenario->creator?->name,
                    'email' => $planningScenario->creator?->email,
                ],
                'depot' => [
                    'id' => $planningScenario->depot?->id,
                    'code' => $planningScenario->depot?->code,
                    'name' => $planningScenario->depot?->name,
                    'address' => $planningScenario->depot?->address,
                ],
            ],
            'comparison' => $buildBogotaPlanningComparisonService->build($planningScenario),
        ]);
    }
}
