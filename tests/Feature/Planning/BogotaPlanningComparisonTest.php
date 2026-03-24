<?php

namespace Tests\Feature\Planning;

use App\Models\Branch;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\PlanningScenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BogotaPlanningComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_a_bogota_only_real_vs_proposed_comparison_for_a_planning_scenario(): void
    {
        config([
            'routing.provider' => 'mock',
            'routing.cache_ttl_seconds' => 3600,
        ]);

        $user = User::factory()->planner()->create();

        $bogotaDepot = Depot::create([
            'code' => 'DEPOT-550',
            'name' => 'BODEGA PRINCIPAL BOGOTA',
            'address' => 'Autopista Medellin KM 3.5 Bogotá',
            'latitude' => 4.6400504,
            'longitude' => -74.1245953,
            'is_active' => true,
        ]);

        $driverAlpha = Driver::create([
            'external_id' => 'DRV-ALPHA',
            'name' => 'Alpha',
            'depot_id' => $bogotaDepot->id,
            'is_active' => true,
        ]);

        $driverBravo = Driver::create([
            'external_id' => 'DRV-BRAVO',
            'name' => 'Bravo',
            'depot_id' => $bogotaDepot->id,
            'is_active' => true,
        ]);

        $branchNorth = Branch::create([
            'code' => 'BR-NORTH',
            'name' => 'Sucursal Norte Bogotá',
            'address' => 'Calle 100 # 15 - 20 Bogotá',
            'latitude' => 4.6900,
            'longitude' => -74.0500,
            'is_active' => true,
        ]);

        $branchWest = Branch::create([
            'code' => 'BR-WEST',
            'name' => 'Sucursal Occidente Bogotá',
            'address' => 'Avenida Calle 80 # 70 - 10 Bogotá',
            'latitude' => 4.7000,
            'longitude' => -74.1100,
            'is_active' => true,
        ]);

        $branchOutside = Branch::create([
            'code' => 'BR-MDE',
            'name' => 'Sucursal Medellín',
            'address' => 'Carrera 45 # 10 - 50 Medellín',
            'latitude' => 6.2442,
            'longitude' => -75.5812,
            'is_active' => true,
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-001',
            'driver_id' => $driverAlpha->id,
            'branch_id' => $branchNorth->id,
            'service_date' => '2026-03-10',
            'historical_sequence' => 1,
            'status' => 'processed',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-002',
            'driver_id' => $driverAlpha->id,
            'branch_id' => $branchNorth->id,
            'service_date' => '2026-03-10',
            'historical_sequence' => 2,
            'status' => 'processed',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-003',
            'driver_id' => $driverAlpha->id,
            'branch_id' => $branchWest->id,
            'service_date' => '2026-03-10',
            'historical_sequence' => 3,
            'status' => 'processed',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-004',
            'driver_id' => $driverAlpha->id,
            'branch_id' => $branchOutside->id,
            'service_date' => '2026-03-10',
            'historical_sequence' => 4,
            'status' => 'processed',
        ]);

        $this->actingAs($user)
            ->post(route('planning.scenarios.store'), [
                'service_date' => '2026-03-10',
                'depot_id' => $bogotaDepot->id,
            ])
            ->assertRedirect();

        $scenario = PlanningScenario::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('planning.scenarios.comparison', $scenario))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Planning/Comparison')
                ->where('scenario.is_bogota', true)
                ->where('comparison.cut.label', 'Fecha + depot Bogotá')
                ->where('comparison.summary.historical.driver_count', 1)
                ->where('comparison.summary.historical.total_invoices', 3)
                ->where('comparison.summary.proposed.driver_count', 2)
                ->where('comparison.summary.proposed.total_invoices', 3)
                ->where('comparison.summary.outside_bogota_stops', 1)
                ->where('comparison.summary.outside_bogota_invoices', 1)
                ->where('comparison.summary.redistributed_stops', 1)
                ->where('comparison.summary.reassigned_invoices', 2)
                ->has('comparison.historical_journeys', 1)
                ->has('comparison.proposed_journeys', 2)
                ->has('comparison.driver_comparisons', 2)
                ->has('comparison.excluded_stops.outside_bogota', 1)
            );
    }

    public function test_it_returns_not_found_for_non_bogota_scenarios(): void
    {
        $user = User::factory()->planner()->create();

        $barranquillaDepot = Depot::create([
            'code' => 'DEPOT-2020',
            'name' => 'BODEGA INSTITUCIONAL BARRANQUILLA',
            'address' => 'Vía 40 # 71 - 124 Barranquilla',
            'latitude' => 11.0093404,
            'longitude' => -74.7905069,
            'is_active' => true,
        ]);

        $scenario = PlanningScenario::create([
            'depot_id' => $barranquillaDepot->id,
            'created_by' => $user->id,
            'service_date' => '2026-03-10',
            'name' => '2026-03-10 | Barranquilla',
            'status' => 'snapshot_ready',
            'configuration' => ['return_to_depot' => true],
            'summary' => ['total_invoices' => 0],
            'last_generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('planning.scenarios.comparison', $scenario))
            ->assertNotFound();
    }
}
