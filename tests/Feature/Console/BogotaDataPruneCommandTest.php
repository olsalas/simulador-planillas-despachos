<?php

namespace Tests\Feature\Console;

use App\Domain\Ingestion\ConsolidateRouteBatchesService;
use App\Models\Branch;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\PlanningScenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BogotaDataPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_delete_anything_in_dry_run_mode(): void
    {
        $this->seedMixedDataset();

        $before = $this->currentTotals();

        $this->artisan('demo:prune-non-bogota')
            ->assertSuccessful();

        $this->assertSame($before, $this->currentTotals());
    }

    public function test_it_prunes_non_bogota_data_and_rebuilds_derived_tables(): void
    {
        $ids = $this->seedMixedDataset();

        $this->artisan('demo:prune-non-bogota --force')
            ->assertSuccessful();

        $this->assertDatabaseCount('depots', 1);
        $this->assertDatabaseCount('drivers', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('route_batches', 1);
        $this->assertDatabaseCount('invoice_stops', 1);
        $this->assertDatabaseCount('planning_scenarios', 0);

        $this->assertDatabaseHas('invoices', [
            'id' => $ids['kept_invoice_id'],
            'driver_id' => $ids['bogota_driver_id'],
            'branch_id' => $ids['bogota_branch_id'],
        ]);

        $this->assertDatabaseHas('route_batches', [
            'driver_id' => $ids['bogota_driver_id'],
            'service_date' => '2026-03-05',
            'total_invoices' => 1,
            'total_stops' => 1,
            'pending_invoices' => 0,
            'status' => 'ready',
        ]);

        $this->assertDatabaseHas('invoice_stops', [
            'driver_id' => $ids['bogota_driver_id'],
            'branch_id' => $ids['bogota_branch_id'],
            'service_date' => '2026-03-05',
            'invoice_count' => 1,
            'status' => 'ready',
        ]);
    }

    /**
     * @return array{bogota_driver_id: int, bogota_branch_id: int, kept_invoice_id: int}
     */
    private function seedMixedDataset(): array
    {
        $planner = User::factory()->planner()->create();

        $bogotaDepot = Depot::create([
            'code' => 'DEPOT-550',
            'name' => 'BODEGA PRINCIPAL BOGOTA',
            'address' => 'Autopista Medellin KM 3.5 Bogotá',
            'latitude' => 4.6400504,
            'longitude' => -74.1245953,
            'is_active' => true,
        ]);

        $outsideDepot = Depot::create([
            'code' => 'DEPOT-2020',
            'name' => 'BODEGA BARRANQUILLA',
            'address' => 'Vía 40 # 71 - 124 Barranquilla',
            'latitude' => 11.0093404,
            'longitude' => -74.7905069,
            'is_active' => true,
        ]);

        $bogotaDriver = Driver::create([
            'external_id' => 'DRV-BOG',
            'name' => 'Conductor Bogotá',
            'depot_id' => $bogotaDepot->id,
            'is_active' => true,
        ]);

        $outsideDriver = Driver::create([
            'external_id' => 'DRV-BAQ',
            'name' => 'Conductor Barranquilla',
            'depot_id' => $outsideDepot->id,
            'is_active' => true,
        ]);

        $bogotaBranch = Branch::create([
            'code' => 'BR-BOG',
            'name' => 'Sucursal Norte Bogotá',
            'address' => 'Calle 100 # 15 - 20 Bogotá',
            'latitude' => 4.6900,
            'longitude' => -74.0500,
            'is_active' => true,
        ]);

        $outsideBranch = Branch::create([
            'code' => 'BR-BAQ',
            'name' => 'Sucursal Barranquilla',
            'address' => 'Vía 40 # 71 - 124 Barranquilla',
            'latitude' => 11.0200,
            'longitude' => -74.8500,
            'is_active' => true,
        ]);

        $keptInvoice = Invoice::create([
            'external_invoice_id' => 'INV-BOG-KEEP',
            'driver_id' => $bogotaDriver->id,
            'branch_id' => $bogotaBranch->id,
            'service_date' => '2026-03-05',
            'historical_sequence' => 1,
            'status' => 'ready',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-BOG-OUTSIDE-BRANCH',
            'driver_id' => $bogotaDriver->id,
            'branch_id' => $outsideBranch->id,
            'service_date' => '2026-03-05',
            'historical_sequence' => 2,
            'status' => 'ready',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-OUTSIDE-DRIVER',
            'driver_id' => $outsideDriver->id,
            'branch_id' => $bogotaBranch->id,
            'service_date' => '2026-03-05',
            'historical_sequence' => 1,
            'status' => 'ready',
        ]);

        app(ConsolidateRouteBatchesService::class)->rebuildAll();

        PlanningScenario::create([
            'depot_id' => $bogotaDepot->id,
            'created_by' => $planner->id,
            'service_date' => '2026-03-05',
            'name' => '2026-03-05 | Bogotá',
            'status' => 'snapshot_ready',
            'configuration' => ['return_to_depot' => true],
            'summary' => ['total_invoices' => 3],
            'last_generated_at' => now(),
        ]);

        return [
            'bogota_driver_id' => $bogotaDriver->id,
            'bogota_branch_id' => $bogotaBranch->id,
            'kept_invoice_id' => $keptInvoice->id,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function currentTotals(): array
    {
        return [
            'depots' => Depot::count(),
            'drivers' => Driver::count(),
            'branches' => Branch::count(),
            'invoices' => Invoice::count(),
            'route_batches' => \App\Models\RouteBatch::count(),
            'invoice_stops' => \App\Models\InvoiceStop::count(),
            'planning_scenarios' => PlanningScenario::count(),
        ];
    }
}
