<?php

namespace Tests\Feature\Simulation;

use App\Models\Driver;
use App\Models\RouteBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SimulationRunScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_simulation_screen_without_a_default_selected_batch(): void
    {
        config([
            'routing.provider' => 'mock',
        ]);

        $user = User::factory()->create();

        $driver = Driver::create([
            'external_id' => 'DRV-501',
            'name' => 'Conductor Demo',
            'is_active' => true,
        ]);

        $batch = RouteBatch::create([
            'driver_id' => $driver->id,
            'service_date' => '2026-03-06',
            'total_invoices' => 12,
            'total_stops' => 4,
            'pending_invoices' => 1,
            'status' => 'partial',
        ]);

        $this->actingAs($user)
            ->get(route('simulation.run'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Simulation/Run')
                ->where('defaultBatchId', null)
                ->has('batches', 1)
                ->where('batches.0.id', $batch->id)
                ->where('batches.0.service_date', '2026-03-06')
                ->where('batches.0.driver_name', 'Conductor Demo')
                ->where('batches.0.driver_external_id', 'DRV-501')
                ->where('batches.0.total_stops', 4)
                ->where('batches.0.pending_invoices', 1)
                ->where('routing.configured_provider', 'mock')
                ->where('routing.effective_provider', 'mock')
            );
    }
}
