<?php

namespace Tests\Feature\Auth;

use App\Models\Depot;
use App\Models\PlanningScenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_role_exposes_expected_frontend_abilities(): void
    {
        $user = User::factory()->planner()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('auth.user.role', User::ROLE_PLANNER)
                ->where('auth.user.role_label', 'Planificador')
                ->where('auth.abilities.upload_csv', false)
                ->where('auth.abilities.manage_planning', true)
                ->where('auth.abilities.view_planning', true)
                ->where('auth.abilities.view_simulation', true)
                ->where('auth.abilities.view_batches', true)
            );
    }

    public function test_viewer_can_access_read_only_operational_screens(): void
    {
        $user = User::factory()->viewer()->create();
        $depot = Depot::create([
            'code' => 'DEP-01',
            'name' => 'Depot Norte',
            'address' => 'Calle 1',
            'latitude' => 4.7110,
            'longitude' => -74.0721,
            'is_active' => true,
        ]);

        $scenario = PlanningScenario::create([
            'depot_id' => $depot->id,
            'created_by' => $user->id,
            'service_date' => '2026-03-07',
            'name' => '2026-03-07 | Depot Norte',
            'status' => 'snapshot_ready',
            'configuration' => ['return_to_depot' => true],
            'summary' => [],
            'last_generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('planning.scenarios.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('planning.scenarios.show', $scenario))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('simulation.run'))
            ->assertOk();
    }

    public function test_viewer_cannot_create_or_allocate_planning_scenarios(): void
    {
        $user = User::factory()->viewer()->create();
        $depot = Depot::create([
            'code' => 'DEP-01',
            'name' => 'Depot Norte',
            'address' => 'Calle 1',
            'latitude' => 4.7110,
            'longitude' => -74.0721,
            'is_active' => true,
        ]);

        $scenario = PlanningScenario::create([
            'depot_id' => $depot->id,
            'created_by' => $user->id,
            'service_date' => '2026-03-07',
            'name' => '2026-03-07 | Depot Norte',
            'status' => 'snapshot_ready',
            'configuration' => ['return_to_depot' => true],
            'summary' => [],
            'last_generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('planning.scenarios.store'), [
                'service_date' => '2026-03-07',
                'depot_id' => $depot->id,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('planning.scenarios.allocate', $scenario))
            ->assertForbidden();
    }

    public function test_planner_can_manage_planning_but_cannot_upload_csv(): void
    {
        $user = User::factory()->planner()->create();
        $depot = Depot::create([
            'code' => 'DEP-01',
            'name' => 'Depot Norte',
            'address' => 'Calle 1',
            'latitude' => 4.7110,
            'longitude' => -74.0721,
            'is_active' => true,
        ]);

        $scenario = PlanningScenario::create([
            'depot_id' => $depot->id,
            'created_by' => $user->id,
            'service_date' => '2026-03-07',
            'name' => '2026-03-07 | Depot Norte',
            'status' => 'snapshot_ready',
            'configuration' => ['return_to_depot' => true],
            'summary' => [],
            'last_generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('planning.scenarios.store'), [
                'service_date' => '2026-03-07',
                'depot_id' => $depot->id,
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('planning.scenarios.allocate', $scenario))
            ->assertRedirect(route('planning.scenarios.show', $scenario));

        $this->actingAs($user)
            ->get(route('ingestion.upload'))
            ->assertForbidden();
    }
}
