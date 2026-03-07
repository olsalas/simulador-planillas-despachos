<?php

namespace Tests\Feature\Simulation;

use App\Models\Branch;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\RouteBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JourneyComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_compares_historical_and_suggested_routes_for_the_comparable_subset(): void
    {
        config([
            'routing.provider' => 'mock',
            'routing.cache_ttl_seconds' => 3600,
        ]);

        $user = User::factory()->create();

        $depot = Depot::create([
            'code' => 'CEDIS-1',
            'name' => 'CEDIS Norte',
            'address' => 'Av. Principal 123',
            'latitude' => 19.5000000,
            'longitude' => -99.2000000,
            'is_active' => true,
        ]);

        $driver = Driver::create([
            'external_id' => 'DRV-88',
            'name' => 'Conductor 88',
            'depot_id' => $depot->id,
            'is_active' => true,
        ]);

        $branchA = Branch::create([
            'code' => 'BR-A',
            'name' => 'Sucursal A',
            'address' => 'Calle 1 # 10-20',
            'latitude' => 19.5900000,
            'longitude' => -99.2000000,
            'is_active' => true,
        ]);

        $branchB = Branch::create([
            'code' => 'BR-B',
            'name' => 'Sucursal B',
            'latitude' => 19.5000000,
            'longitude' => -99.1900000,
            'is_active' => true,
        ]);

        $branchC = Branch::create([
            'code' => 'BR-C',
            'name' => 'Sucursal C',
            'latitude' => 19.5050000,
            'longitude' => -99.1950000,
            'is_active' => true,
        ]);

        $branchD = Branch::create([
            'code' => 'BR-D',
            'name' => 'Sucursal D',
            'latitude' => null,
            'longitude' => null,
            'is_active' => true,
        ]);

        $batch = RouteBatch::create([
            'driver_id' => $driver->id,
            'service_date' => '2026-02-09',
            'total_invoices' => 5,
            'total_stops' => 4,
            'pending_invoices' => 1,
            'status' => 'partial',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-A1',
            'service_date' => '2026-02-09',
            'driver_id' => $driver->id,
            'branch_id' => $branchA->id,
            'historical_sequence' => 3,
            'status' => 'ready',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-A2',
            'service_date' => '2026-02-09',
            'driver_id' => $driver->id,
            'branch_id' => $branchA->id,
            'historical_sequence' => 1,
            'status' => 'ready',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-B1',
            'service_date' => '2026-02-09',
            'driver_id' => $driver->id,
            'branch_id' => $branchB->id,
            'historical_sequence' => 2,
            'status' => 'ready',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-C1',
            'service_date' => '2026-02-09',
            'driver_id' => $driver->id,
            'branch_id' => $branchC->id,
            'historical_sequence' => null,
            'status' => 'ready',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-D1',
            'service_date' => '2026-02-09',
            'driver_id' => $driver->id,
            'branch_id' => $branchD->id,
            'historical_sequence' => 4,
            'status' => 'pending',
            'outlier_reason' => 'branch_without_geocode',
        ]);

        $response = $this->actingAs($user)->postJson(route('simulation.compare'), [
            'route_batch_id' => $batch->id,
            'return_to_depot' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('journey.summary.total_invoices', 5)
            ->assertJsonPath('journey.summary.total_stops', 4)
            ->assertJsonPath('journey.summary.comparable_stops', 2)
            ->assertJsonPath('journey.summary.non_comparable_stops', 1)
            ->assertJsonPath('journey.summary.excluded_stops', 1)
            ->assertJsonPath('historical_route.label', 'Como fue')
            ->assertJsonPath('historical_route.depot.address', 'Av. Principal 123')
            ->assertJsonPath('historical_route.stops.0.branch_code', 'BR-A')
            ->assertJsonPath('historical_route.stops.0.branch_address', 'Calle 1 # 10-20')
            ->assertJsonPath('historical_route.stops.0.historical_sequence', 1)
            ->assertJsonPath('historical_route.stops.1.branch_code', 'BR-B')
            ->assertJsonPath('suggested_route.label', 'Como pudo ser')
            ->assertJsonPath('suggested_route.algorithm', 'nearest_neighbor')
            ->assertJsonPath('suggested_route.stops.0.branch_code', 'BR-B')
            ->assertJsonPath('suggested_route.stops.1.branch_code', 'BR-A')
            ->assertJsonPath('non_comparable_stops.0.branch_code', 'BR-C')
            ->assertJsonPath('non_comparable_stops.0.reason', 'missing_historical_sequence')
            ->assertJsonPath('excluded_stops.0.branch.code', 'BR-D')
            ->assertJsonPath('excluded_stops.0.branch.address', null)
            ->assertJsonPath('excluded_stops.0.reason', 'missing_branch_geocode');

        $this->assertGreaterThan(
            $response->json('suggested_route.metrics.distance_meters'),
            $response->json('historical_route.metrics.distance_meters')
        );

        $this->assertLessThan(0, $response->json('delta.distance_meters'));
    }

    public function test_it_uses_here_matrix_costs_for_suggested_order_when_here_is_active(): void
    {
        config([
            'routing.provider' => 'here',
            'routing.cache_ttl_seconds' => 3600,
            'services.here.api_key' => 'test-here-key',
        ]);

        Http::fake([
            'https://matrix.router.hereapi.com/v8/matrix*' => Http::response([
                'matrixId' => 'matrix-test',
                'matrix' => [
                    'numOrigins' => 3,
                    'numDestinations' => 3,
                    'travelTimes' => [
                        0, 1200, 300,
                        900, 0, 700,
                        400, 800, 0,
                    ],
                    'distances' => [
                        0, 10000, 2000,
                        9000, 0, 7000,
                        3000, 5000, 0,
                    ],
                ],
                'regionDefinition' => [
                    'type' => 'world',
                ],
            ], 200),
            'https://router.hereapi.com/v8/routes*' => Http::response([
                'routes' => [[
                    'sections' => [[
                        'summary' => [
                            'length' => 1000,
                            'duration' => 120,
                        ],
                        'departure' => [
                            'place' => [
                                'location' => [
                                    'lat' => 19.5000000,
                                    'lng' => -99.2000000,
                                ],
                            ],
                        ],
                        'arrival' => [
                            'place' => [
                                'location' => [
                                    'lat' => 19.5100000,
                                    'lng' => -99.1900000,
                                ],
                            ],
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();

        $depot = Depot::create([
            'code' => 'CEDIS-2',
            'name' => 'CEDIS Centro',
            'address' => 'Carrera 15 # 90-10',
            'latitude' => 19.5000000,
            'longitude' => -99.2000000,
            'is_active' => true,
        ]);

        $driver = Driver::create([
            'external_id' => 'DRV-99',
            'name' => 'Conductor 99',
            'depot_id' => $depot->id,
            'is_active' => true,
        ]);

        $branchA = Branch::create([
            'code' => 'BR-A',
            'name' => 'Sucursal A',
            'latitude' => 19.5900000,
            'longitude' => -99.2000000,
            'is_active' => true,
        ]);

        $branchB = Branch::create([
            'code' => 'BR-B',
            'name' => 'Sucursal B',
            'latitude' => 19.5000000,
            'longitude' => -99.1900000,
            'is_active' => true,
        ]);

        $batch = RouteBatch::create([
            'driver_id' => $driver->id,
            'service_date' => '2026-02-10',
            'total_invoices' => 2,
            'total_stops' => 2,
            'pending_invoices' => 0,
            'status' => 'ready',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-H-A',
            'service_date' => '2026-02-10',
            'driver_id' => $driver->id,
            'branch_id' => $branchA->id,
            'historical_sequence' => 1,
            'status' => 'ready',
        ]);

        Invoice::create([
            'external_invoice_id' => 'INV-H-B',
            'service_date' => '2026-02-10',
            'driver_id' => $driver->id,
            'branch_id' => $branchB->id,
            'historical_sequence' => 2,
            'status' => 'ready',
        ]);

        $response = $this->actingAs($user)->postJson(route('simulation.compare'), [
            'route_batch_id' => $batch->id,
            'return_to_depot' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('historical_route.provider', 'here')
            ->assertJsonPath('suggested_route.provider', 'here')
            ->assertJsonPath('historical_route.stops.0.branch_code', 'BR-A')
            ->assertJsonPath('suggested_route.stops.0.branch_code', 'BR-B')
            ->assertJsonPath('suggested_route.stops.1.branch_code', 'BR-A');

        Http::assertSentCount(3);
    }
}
