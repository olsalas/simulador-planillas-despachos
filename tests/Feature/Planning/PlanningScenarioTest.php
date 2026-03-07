<?php

namespace Tests\Feature\Planning;

use App\Models\Branch;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\PlanningScenario;
use App\Models\RouteBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlanningScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_planning_scenarios_index_screen(): void
    {
        $user = User::factory()->create();
        $depot = Depot::create([
            'code' => 'DEP-01',
            'name' => 'Depot Norte',
            'address' => 'Calle 1',
            'latitude' => 4.7110,
            'longitude' => -74.0721,
            'is_active' => true,
        ]);

        Driver::create([
            'external_id' => 'DRV-01',
            'name' => 'Conductor Activo',
            'depot_id' => $depot->id,
            'is_active' => true,
        ]);

        PlanningScenario::create([
            'depot_id' => $depot->id,
            'created_by' => $user->id,
            'service_date' => '2026-03-07',
            'name' => '2026-03-07 | Depot Norte',
            'status' => 'snapshot_ready',
            'configuration' => ['return_to_depot' => true],
            'summary' => [
                'total_invoices' => 12,
                'eligible_stops' => 4,
                'excluded_stops' => 1,
                'active_drivers_in_depot' => 1,
            ],
            'last_generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('planning.scenarios.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Planning/Index')
                ->has('depots', 1)
                ->has('scenarios', 1)
                ->where('depots.0.code', 'DEP-01')
                ->where('depots.0.active_drivers_count', 1)
                ->where('scenarios.0.service_date', '2026-03-07')
                ->where('scenarios.0.summary.total_invoices', 12)
            );
    }

    public function test_it_creates_and_refreshes_a_planning_scenario_snapshot(): void
    {
        $user = User::factory()->create();

        $depot = Depot::create([
            'code' => 'DEP-01',
            'name' => 'Depot Norte',
            'address' => 'Calle 1',
            'latitude' => 4.7110,
            'longitude' => -74.0721,
            'is_active' => true,
        ]);

        $otherDepot = Depot::create([
            'code' => 'DEP-02',
            'name' => 'Depot Sur',
            'address' => 'Calle 2',
            'latitude' => 4.6500,
            'longitude' => -74.1100,
            'is_active' => true,
        ]);

        $driverOne = Driver::create([
            'external_id' => 'DRV-01',
            'name' => 'Conductor Uno',
            'depot_id' => $depot->id,
            'is_active' => true,
        ]);

        $driverTwo = Driver::create([
            'external_id' => 'DRV-02',
            'name' => 'Conductor Dos',
            'depot_id' => $depot->id,
            'is_active' => true,
        ]);

        $otherDriver = Driver::create([
            'external_id' => 'DRV-03',
            'name' => 'Otro Conductor',
            'depot_id' => $otherDepot->id,
            'is_active' => true,
        ]);

        $eligibleBranch = Branch::create([
            'code' => 'BR-01',
            'name' => 'Sucursal Elegible',
            'address' => 'Cra 1',
            'latitude' => 4.7120,
            'longitude' => -74.0710,
        ]);

        $missingGeoBranch = Branch::create([
            'code' => 'BR-02',
            'name' => 'Sucursal Sin Geo',
            'address' => 'Cra 2',
            'latitude' => null,
            'longitude' => null,
        ]);

        RouteBatch::create([
            'driver_id' => $driverOne->id,
            'service_date' => '2026-03-07',
            'total_invoices' => 2,
            'total_stops' => 1,
            'pending_invoices' => 0,
            'status' => 'ready',
        ]);

        RouteBatch::create([
            'driver_id' => $driverTwo->id,
            'service_date' => '2026-03-07',
            'total_invoices' => 2,
            'total_stops' => 2,
            'pending_invoices' => 1,
            'status' => 'partial',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-01',
            'driver_id' => $driverOne->id,
            'branch_id' => $eligibleBranch->id,
            'service_date' => '2026-03-07',
            'historical_sequence' => 1,
            'status' => 'processed',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-02',
            'driver_id' => $driverOne->id,
            'branch_id' => $eligibleBranch->id,
            'service_date' => '2026-03-07',
            'historical_sequence' => 2,
            'status' => 'processed',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-03',
            'driver_id' => $driverTwo->id,
            'branch_id' => $missingGeoBranch->id,
            'service_date' => '2026-03-07',
            'historical_sequence' => 3,
            'status' => 'pending',
            'outlier_reason' => 'branch_without_geocode',
        ]);

        $missingBranchInvoice = Invoice::create([
            'external_invoice_id' => 'INV-04',
            'driver_id' => $driverTwo->id,
            'branch_id' => null,
            'service_date' => '2026-03-07',
            'historical_sequence' => 4,
            'status' => 'pending',
            'outlier_reason' => 'branch_not_found',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-05',
            'driver_id' => $otherDriver->id,
            'branch_id' => $eligibleBranch->id,
            'service_date' => '2026-03-07',
            'historical_sequence' => 1,
            'status' => 'processed',
        ]);

        $this->actingAs($user)
            ->post(route('planning.scenarios.store'), [
                'service_date' => '2026-03-07',
                'depot_id' => $depot->id,
            ])
            ->assertRedirect();

        $scenario = PlanningScenario::query()->firstOrFail();

        $this->assertDatabaseCount('planning_scenarios', 1);
        $this->assertSame('snapshot_ready', $scenario->status);
        $this->assertSame(4, $scenario->summary['total_invoices']);
        $this->assertSame(2, $scenario->summary['eligible_invoices']);
        $this->assertSame(2, $scenario->summary['excluded_invoices']);
        $this->assertSame(1, $scenario->summary['eligible_stops']);
        $this->assertSame(2, $scenario->summary['excluded_stops']);
        $this->assertSame(2, $scenario->summary['active_drivers_in_depot']);
        $this->assertSame(2, $scenario->summary['historical_route_batches']);

        $this->assertDatabaseHas('planning_scenario_stops', [
            'planning_scenario_id' => $scenario->id,
            'stop_key' => 'branch:'.$eligibleBranch->id,
            'status' => 'pending_assignment',
            'invoice_count' => 2,
        ]);

        $this->assertDatabaseHas('planning_scenario_stops', [
            'planning_scenario_id' => $scenario->id,
            'stop_key' => 'branch:'.$missingGeoBranch->id,
            'status' => 'excluded',
            'exclusion_reason' => 'missing_branch_geocode',
        ]);

        $this->assertDatabaseHas('planning_scenario_stops', [
            'planning_scenario_id' => $scenario->id,
            'stop_key' => 'invoice:'.$missingBranchInvoice->id,
            'status' => 'excluded',
            'exclusion_reason' => 'branch_not_found',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-06',
            'driver_id' => $driverOne->id,
            'branch_id' => $eligibleBranch->id,
            'service_date' => '2026-03-07',
            'historical_sequence' => 5,
            'status' => 'processed',
        ]);

        $this->actingAs($user)
            ->post(route('planning.scenarios.store'), [
                'service_date' => '2026-03-07',
                'depot_id' => $depot->id,
            ])
            ->assertRedirect(route('planning.scenarios.show', $scenario));

        $scenario->refresh();

        $this->assertDatabaseCount('planning_scenarios', 1);
        $this->assertSame(5, $scenario->summary['total_invoices']);
        $this->assertSame(3, $scenario->summary['eligible_invoices']);
        $this->assertSame(3, $scenario->stops()->where('status', 'pending_assignment')->value('invoice_count'));

        $this->actingAs($user)
            ->get(route('planning.scenarios.show', $scenario))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Planning/Show')
                ->where('scenario.service_date', '2026-03-07')
                ->where('scenario.depot.code', 'DEP-01')
                ->where('scenario.summary.total_invoices', 5)
                ->has('eligibleStops', 1)
                ->has('excludedStops', 2)
                ->has('drivers', 2)
            );
    }
}
