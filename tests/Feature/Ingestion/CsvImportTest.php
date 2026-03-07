<?php

namespace Tests\Feature\Ingestion;

use App\Models\Branch;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_csv_and_stores_valid_and_invalid_rows(): void
    {
        $user = User::factory()->canUploadCsv()->create();

        Branch::create([
            'code' => 'BR-001',
            'name' => 'Sucursal Centro',
            'latitude' => 19.4326000,
            'longitude' => -99.1332000,
            'is_active' => true,
        ]);

        $csv = <<<CSV
external_invoice_id,invoice_number,driver_external_id,driver_name,service_date,branch_code,historical_sequence,historical_latitude,historical_longitude
INV-001,F-001,DRV-001,Conductor Uno,2026-02-01,BR-001,1,19.43,-99.13
,F-002,DRV-001,Conductor Uno,2026-02-01,BR-001,2,19.44,-99.12
CSV;

        $file = UploadedFile::fake()->createWithContent('invoices.csv', $csv);

        $response = $this->actingAs($user)->post(route('ingestion.upload.store'), [
            'type' => 'invoices',
            'file' => $file,
        ]);

        $response->assertRedirect(route('ingestion.upload'));

        $this->assertDatabaseHas('ingestion_batches', [
            'type' => 'invoices',
            'total_rows' => 2,
            'valid_rows' => 1,
            'invalid_rows' => 1,
        ]);

        $this->assertDatabaseHas('ingestion_rows', [
            'row_number' => 3,
            'status' => 'invalid',
        ]);

        $this->assertDatabaseHas('invoices', [
            'external_invoice_id' => 'INV-001',
            'status' => 'ready',
            'outlier_reason' => null,
        ]);
    }

    public function test_it_consolidates_invoices_into_stops_and_updates_batch_totals(): void
    {
        $user = User::factory()->canUploadCsv()->create();

        Branch::create([
            'code' => 'BR-001',
            'name' => 'Sucursal Norte',
            'latitude' => 20.0000000,
            'longitude' => -99.0000000,
            'is_active' => true,
        ]);

        $csv = <<<CSV
external_invoice_id,invoice_number,driver_external_id,driver_name,service_date,branch_code,historical_sequence,historical_latitude,historical_longitude
INV-101,F-101,DRV-900,Conductor Nueve,2026-02-05,BR-001,1,20.00,-99.00
INV-102,F-102,DRV-900,Conductor Nueve,2026-02-05,BR-001,2,20.01,-99.01
INV-103,F-103,DRV-900,Conductor Nueve,2026-02-05,BR-404,3,20.02,-99.02
CSV;

        $file = UploadedFile::fake()->createWithContent('invoices.csv', $csv);

        $response = $this->actingAs($user)->post(route('ingestion.upload.store'), [
            'type' => 'invoices',
            'file' => $file,
        ]);

        $response->assertRedirect(route('ingestion.upload'));

        $driver = Driver::query()->where('external_id', 'DRV-900')->firstOrFail();

        $this->assertDatabaseHas('route_batches', [
            'driver_id' => $driver->id,
            'service_date' => '2026-02-05',
            'total_invoices' => 3,
            'total_stops' => 1,
            'pending_invoices' => 1,
            'status' => 'partial',
        ]);

        $this->assertDatabaseHas('invoice_stops', [
            'driver_id' => $driver->id,
            'service_date' => '2026-02-05',
            'invoice_count' => 2,
            'status' => 'ready',
        ]);

        $this->assertDatabaseHas('invoices', [
            'external_invoice_id' => 'INV-103',
            'status' => 'pending',
            'outlier_reason' => 'branch_not_found',
        ]);
    }

    public function test_non_privileged_users_cannot_access_csv_upload_routes(): void
    {
        $user = User::factory()->create();

        $getResponse = $this->actingAs($user)->get(route('ingestion.upload'));
        $getResponse->assertForbidden();

        $file = UploadedFile::fake()->createWithContent('invoices.csv', "external_invoice_id,driver_external_id,service_date,branch_code\nINV-1,DRV-1,2026-02-01,BR-001\n");

        $postResponse = $this->actingAs($user)->post(route('ingestion.upload.store'), [
            'type' => 'invoices',
            'file' => $file,
        ]);

        $postResponse->assertForbidden();
        $this->assertDatabaseCount('ingestion_batches', 0);
    }
}
