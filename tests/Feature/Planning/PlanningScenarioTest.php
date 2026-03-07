<?php

namespace Tests\Feature\Planning;

use App\Models\Branch;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\PlanningScenario;
use App\Models\PlanningScenarioJourney;
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
                ->has('candidateStops', 1)
                ->has('excludedStops', 2)
                ->has('unassignedStops', 0)
                ->has('proposedJourneys', 0)
                ->has('drivers', 2)
            );
    }

    public function test_it_generates_a_base_allocation_for_a_planning_scenario(): void
    {
        config([
            'routing.provider' => 'mock',
        ]);

        $user = User::factory()->create();

        $depot = Depot::create([
            'code' => 'DEP-01',
            'name' => 'Depot Norte',
            'address' => 'Calle 1',
            'latitude' => 4.7110,
            'longitude' => -74.0721,
            'is_active' => true,
        ]);

        $driverAlpha = Driver::create([
            'external_id' => 'DRV-ALPHA',
            'name' => 'Alpha',
            'depot_id' => $depot->id,
            'is_active' => true,
        ]);

        $driverBravo = Driver::create([
            'external_id' => 'DRV-BRAVO',
            'name' => 'Bravo',
            'depot_id' => $depot->id,
            'is_active' => true,
        ]);

        $branchNorth = Branch::create([
            'code' => 'BR-N',
            'name' => 'Sucursal Norte',
            'address' => 'Norte 1',
            'latitude' => 4.7400,
            'longitude' => -74.0500,
        ]);

        $branchEast = Branch::create([
            'code' => 'BR-E',
            'name' => 'Sucursal Este',
            'address' => 'Este 1',
            'latitude' => 4.7100,
            'longitude' => -74.0100,
        ]);

        $branchSouth = Branch::create([
            'code' => 'BR-S',
            'name' => 'Sucursal Sur',
            'address' => 'Sur 1',
            'latitude' => 4.6700,
            'longitude' => -74.0600,
        ]);

        $branchWest = Branch::create([
            'code' => 'BR-W',
            'name' => 'Sucursal Oeste',
            'address' => 'Oeste 1',
            'latitude' => 4.7000,
            'longitude' => -74.1200,
        ]);

        $scenario = PlanningScenario::create([
            'depot_id' => $depot->id,
            'created_by' => $user->id,
            'service_date' => '2026-03-07',
            'name' => '2026-03-07 | Depot Norte',
            'status' => 'snapshot_ready',
            'configuration' => [
                'return_to_depot' => true,
                'prioritize_proximity' => true,
                'respect_zones' => false,
                'allow_cross_zone_assignment' => true,
                'max_stops_per_driver' => 2,
                'max_invoices_per_journey' => 5,
            ],
            'summary' => [
                'total_invoices' => 7,
                'eligible_invoices' => 7,
                'excluded_invoices' => 0,
                'total_stops' => 4,
                'eligible_stops' => 4,
                'excluded_stops' => 0,
                'active_drivers_in_depot' => 2,
                'historical_route_batches' => 2,
            ],
            'last_generated_at' => now(),
        ]);

        $scenario->stops()->createMany([
            [
                'stop_key' => 'branch:'.$branchNorth->id,
                'branch_id' => $branchNorth->id,
                'status' => 'pending_assignment',
                'branch_code' => $branchNorth->code,
                'branch_name' => $branchNorth->name,
                'branch_address' => $branchNorth->address,
                'latitude' => $branchNorth->latitude,
                'longitude' => $branchNorth->longitude,
                'invoice_count' => 2,
                'historical_sequence_min' => 1,
                'invoice_ids' => [101, 102],
            ],
            [
                'stop_key' => 'branch:'.$branchEast->id,
                'branch_id' => $branchEast->id,
                'status' => 'pending_assignment',
                'branch_code' => $branchEast->code,
                'branch_name' => $branchEast->name,
                'branch_address' => $branchEast->address,
                'latitude' => $branchEast->latitude,
                'longitude' => $branchEast->longitude,
                'invoice_count' => 2,
                'historical_sequence_min' => 2,
                'invoice_ids' => [103, 104],
            ],
            [
                'stop_key' => 'branch:'.$branchSouth->id,
                'branch_id' => $branchSouth->id,
                'status' => 'pending_assignment',
                'branch_code' => $branchSouth->code,
                'branch_name' => $branchSouth->name,
                'branch_address' => $branchSouth->address,
                'latitude' => $branchSouth->latitude,
                'longitude' => $branchSouth->longitude,
                'invoice_count' => 2,
                'historical_sequence_min' => 3,
                'invoice_ids' => [105, 106],
            ],
            [
                'stop_key' => 'branch:'.$branchWest->id,
                'branch_id' => $branchWest->id,
                'status' => 'pending_assignment',
                'branch_code' => $branchWest->code,
                'branch_name' => $branchWest->name,
                'branch_address' => $branchWest->address,
                'latitude' => $branchWest->latitude,
                'longitude' => $branchWest->longitude,
                'invoice_count' => 1,
                'historical_sequence_min' => 4,
                'invoice_ids' => [107],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('planning.scenarios.allocate', $scenario))
            ->assertRedirect(route('planning.scenarios.show', $scenario));

        $scenario->refresh();

        $this->assertSame('allocation_ready', $scenario->status);
        $this->assertSame(2, $scenario->summary['proposed_journeys']);
        $this->assertSame(4, $scenario->summary['allocated_stops']);
        $this->assertSame(7, $scenario->summary['allocated_invoices']);
        $this->assertSame(0, $scenario->summary['unassigned_stops']);

        $this->assertDatabaseCount('planning_scenario_journeys', 2);
        $this->assertDatabaseCount('planning_scenario_stops', 4);
        $this->assertDatabaseHas('planning_scenario_stops', [
            'planning_scenario_id' => $scenario->id,
            'status' => 'assigned',
            'assigned_driver_id' => $driverAlpha->id,
            'suggested_sequence' => 1,
        ]);

        $journeys = PlanningScenarioJourney::query()
            ->where('planning_scenario_id', $scenario->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $journeys);
        $this->assertSame(2, $journeys[0]->total_stops);
        $this->assertSame('mock', $journeys[0]->summary['provider']);
        $this->assertGreaterThan(0, $journeys[0]->summary['distance_meters']);
        $this->assertNotEmpty($journeys[0]->summary['geometry']);
        $this->assertSame('planning_scenario_depot', $journeys[0]->summary['depot']['source']);

        $this->actingAs($user)
            ->get(route('planning.scenarios.show', $scenario))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Planning/Show')
                ->where('scenario.status', 'allocation_ready')
                ->where('scenario.summary.proposed_journeys', 2)
                ->has('proposedJourneys', 2)
                ->where('proposedJourneys.0.route_preview.provider', 'mock')
                ->where('proposedJourneys.0.route_preview.depot.source', 'planning_scenario_depot')
                ->has('candidateStops', 4)
                ->has('unassignedStops', 0)
            );
    }
}
