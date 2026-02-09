<?php

namespace Tests\Feature\Simulation;

use App\Models\Branch;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\InvoiceStop;
use App\Models\RouteBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_route_preview_and_hits_cache_on_second_request(): void
    {
        config([
            'routing.provider' => 'mock',
            'routing.cache_ttl_seconds' => 3600,
        ]);

        $user = User::factory()->create();

        $depot = Depot::create([
            'code' => 'CEDIS-1',
            'name' => 'CEDIS Norte',
            'latitude' => 19.5000000,
            'longitude' => -99.2000000,
            'is_active' => true,
        ]);

        $driver = Driver::create([
            'external_id' => 'DRV-77',
            'name' => 'Conductor 77',
            'depot_id' => $depot->id,
            'is_active' => true,
        ]);

        $branchA = Branch::create([
            'code' => 'BR-A',
            'name' => 'Sucursal A',
            'latitude' => 19.5100000,
            'longitude' => -99.1900000,
            'is_active' => true,
        ]);

        $branchB = Branch::create([
            'code' => 'BR-B',
            'name' => 'Sucursal B',
            'latitude' => 19.5200000,
            'longitude' => -99.1800000,
            'is_active' => true,
        ]);

        $batch = RouteBatch::create([
            'driver_id' => $driver->id,
            'service_date' => '2026-02-08',
            'total_invoices' => 3,
            'total_stops' => 2,
            'pending_invoices' => 0,
            'status' => 'ready',
        ]);

        InvoiceStop::create([
            'driver_id' => $driver->id,
            'branch_id' => $branchA->id,
            'service_date' => '2026-02-08',
            'invoice_count' => 2,
            'planned_sequence' => 1,
            'status' => 'ready',
        ]);

        InvoiceStop::create([
            'driver_id' => $driver->id,
            'branch_id' => $branchB->id,
            'service_date' => '2026-02-08',
            'invoice_count' => 1,
            'planned_sequence' => 2,
            'status' => 'ready',
        ]);

        $payload = [
            'route_batch_id' => $batch->id,
            'return_to_depot' => true,
        ];

        $firstResponse = $this->actingAs($user)->postJson(route('simulation.preview'), $payload);
        $firstResponse->assertOk()
            ->assertJsonPath('provider', 'mock')
            ->assertJsonPath('cache_hit', false)
            ->assertJsonPath('stops.0.sequence', 1)
            ->assertJsonPath('stops.1.sequence', 2);

        $geometry = $firstResponse->json('geometry');
        $this->assertCount(4, $geometry);

        $secondResponse = $this->actingAs($user)->postJson(route('simulation.preview'), $payload);
        $secondResponse->assertOk()
            ->assertJsonPath('provider', 'mock')
            ->assertJsonPath('cache_hit', true);
    }
}
