<?php

use App\Domain\Ingestion\CsvIngestionService;
use App\Models\Branch;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\RouteBatch;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('demo:load-generated-data {--skip-invoices : Skip importing invoice history} {--invoice-chunk-size=1000 : Number of invoice rows per import chunk}', function (CsvIngestionService $csvIngestionService) {
    $generatedPath = base_path('docs/generated');

    foreach ([
        $generatedPath.'/branches_seed.csv',
        $generatedPath.'/depots_seed.csv',
        $generatedPath.'/drivers_import.csv',
        $generatedPath.'/driver_depot_assignment_ready.csv',
    ] as $requiredPath) {
        if (! is_file($requiredPath) || ! is_readable($requiredPath)) {
            $this->error('Missing generated file: '.$requiredPath);

            return 1;
        }
    }

    $branchStats = demoLoadBranches($generatedPath.'/branches_seed.csv');
    $depotStats = demoLoadDepots($generatedPath.'/depots_seed.csv');

    $driverReport = $csvIngestionService->import(
        demoMakeUploadedFile($generatedPath.'/drivers_import.csv'),
        'drivers',
        null,
    );

    $driverDepotStats = demoApplyDriverDepotAssignments($generatedPath.'/driver_depot_assignment_ready.csv');

    $invoiceReport = null;
    if (! $this->option('skip-invoices')) {
        $invoicePath = $generatedPath.'/invoices_import.csv';
        if (! is_file($invoicePath) || ! is_readable($invoicePath)) {
            $this->error('Missing generated file: '.$invoicePath);

            return 1;
        }

        $invoiceReport = demoImportInvoicesInChunks(
            $csvIngestionService,
            $invoicePath,
            max(1, (int) $this->option('invoice-chunk-size')),
            $this,
        );
    }

    $this->info('Generated demo data loaded.');
    $this->newLine();
    $this->line('Branches upserted: '.$branchStats['upserted']);
    $this->line('Depots upserted: '.$depotStats['upserted']);
    $this->line('Drivers imported: '.$driverReport['valid_rows']);
    $this->line('Driver -> depot assignments applied: '.$driverDepotStats['assigned']);

    if ($invoiceReport !== null) {
        $this->line('Invoices imported: '.$invoiceReport['valid_rows']);
        $this->line('Invalid invoice rows: '.$invoiceReport['invalid_rows']);
        $this->line('Affected route batches: '.count($invoiceReport['affected_route_batches'] ?? []));
    }

    $this->newLine();
    $this->line('Current totals:');
    $this->line('- branches: '.Branch::count());
    $this->line('- depots: '.Depot::count());
    $this->line('- drivers: '.Driver::count());
    $this->line('- invoices: '.Invoice::count());
    $this->line('- route_batches: '.RouteBatch::count());

    return 0;
})->purpose('Load generated demo data from docs/generated into the local database');

/**
 * @return array{upserted: int}
 */
if (! function_exists('demoLoadBranches')) {
    function demoLoadBranches(string $path): array
    {
        $count = 0;

        foreach (demoReadCsv($path) as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            Branch::updateOrCreate(
                ['code' => $code],
                [
                    'name' => trim((string) ($row['name'] ?? '')) ?: 'Branch '.$code,
                    'address' => demoNullableString($row['address'] ?? null),
                    'latitude' => demoNullableDecimal($row['latitude'] ?? null),
                    'longitude' => demoNullableDecimal($row['longitude'] ?? null),
                    'is_active' => demoTruthyFlag($row['is_active'] ?? '1'),
                ]
            );

            $count++;
        }

        return ['upserted' => $count];
    }
}

/**
 * @return array{upserted: int}
 */
if (! function_exists('demoLoadDepots')) {
    function demoLoadDepots(string $path): array
    {
        $count = 0;

        foreach (demoReadCsv($path) as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            Depot::updateOrCreate(
                ['code' => $code],
                [
                    'name' => trim((string) ($row['name'] ?? '')) ?: 'Depot '.$code,
                    'address' => demoNullableString($row['address'] ?? null),
                    'latitude' => (float) ($row['latitude'] ?? 0),
                    'longitude' => (float) ($row['longitude'] ?? 0),
                    'is_active' => demoTruthyFlag($row['is_active'] ?? '1'),
                ]
            );

            $count++;
        }

        return ['upserted' => $count];
    }
}

/**
 * @return array{assigned: int}
 */
if (! function_exists('demoApplyDriverDepotAssignments')) {
    function demoApplyDriverDepotAssignments(string $path): array
    {
        $assigned = 0;

        foreach (demoReadCsv($path) as $row) {
            $driverExternalId = trim((string) ($row['driver_external_id'] ?? ''));
            $depotCode = trim((string) ($row['depot_code'] ?? ''));

            if ($driverExternalId === '' || $depotCode === '') {
                continue;
            }

            $driver = Driver::query()->where('external_id', $driverExternalId)->first();
            $depot = Depot::query()->where('code', $depotCode)->first();

            if ($driver === null || $depot === null) {
                continue;
            }

            if ($driver->depot_id !== $depot->id) {
                $driver->update(['depot_id' => $depot->id]);
            }

            $assigned++;
        }

        return ['assigned' => $assigned];
    }
}

/**
 * @return array{
 *     valid_rows: int,
 *     invalid_rows: int,
 *     affected_route_batches: list<int>,
 *     chunks_imported: int
 * }
 */
if (! function_exists('demoImportInvoicesInChunks')) {
    function demoImportInvoicesInChunks(
        CsvIngestionService $csvIngestionService,
        string $path,
        int $chunkSize,
        object $command,
    ): array {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open '.$path);
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);

            return [
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'affected_route_batches' => [],
                'chunks_imported' => 0,
            ];
        }

        $validRows = 0;
        $invalidRows = 0;
        $affectedRouteBatches = [];
        $chunksImported = 0;
        $chunkRows = 0;
        $chunkPath = null;
        $chunkHandle = null;

        $flushChunk = function () use (
            &$chunkHandle,
            &$chunkPath,
            &$chunkRows,
            &$chunksImported,
            &$validRows,
            &$invalidRows,
            &$affectedRouteBatches,
            $csvIngestionService,
            $command,
        ): void {
            if ($chunkHandle === null || $chunkPath === null || $chunkRows === 0) {
                return;
            }

            fclose($chunkHandle);
            $chunkHandle = null;

            $chunksImported++;
            $command->line("Importing invoice chunk {$chunksImported} ({$chunkRows} rows)...");

            $report = $csvIngestionService->import(
                demoMakeUploadedFile($chunkPath),
                'invoices',
                null,
            );

            $validRows += (int) ($report['valid_rows'] ?? 0);
            $invalidRows += (int) ($report['invalid_rows'] ?? 0);

            foreach (($report['affected_route_batches'] ?? []) as $routeBatchId) {
                $affectedRouteBatches[(int) $routeBatchId] = true;
            }

            @unlink($chunkPath);
            $chunkPath = null;
            $chunkRows = 0;
        };

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            if ($chunkHandle === null) {
                $chunkPath = tempnam(sys_get_temp_dir(), 'demo_invoices_');
                if ($chunkPath === false) {
                    throw new RuntimeException('Unable to create temporary invoice chunk file.');
                }

                $chunkHandle = fopen($chunkPath, 'wb');
                if ($chunkHandle === false) {
                    throw new RuntimeException('Unable to write temporary invoice chunk file.');
                }

                fputcsv($chunkHandle, $headers);
            }

            fputcsv($chunkHandle, $data);
            $chunkRows++;

            if ($chunkRows >= $chunkSize) {
                $flushChunk();
            }
        }

        fclose($handle);
        $flushChunk();

        return [
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'affected_route_batches' => array_keys($affectedRouteBatches),
            'chunks_imported' => $chunksImported,
        ];
    }
}

/**
 * @return list<array<string, string>>
 */
if (! function_exists('demoReadCsv')) {
    function demoReadCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open '.$path);
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $headers = array_map(static fn ($value): string => trim((string) $value), $headers);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($data[$index] ?? ''));
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}

if (! function_exists('demoMakeUploadedFile')) {
    function demoMakeUploadedFile(string $path): UploadedFile
    {
        return new UploadedFile(
            $path,
            basename($path),
            'text/csv',
            test: true,
        );
    }
}

if (! function_exists('demoNullableString')) {
    function demoNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

if (! function_exists('demoNullableDecimal')) {
    function demoNullableDecimal(mixed $value): ?float
    {
        $value = trim((string) $value);

        return $value === '' ? null : (float) $value;
    }
}

if (! function_exists('demoTruthyFlag')) {
    function demoTruthyFlag(mixed $value): bool
    {
        return in_array(strtoupper(trim((string) $value)), ['1', 'TRUE', 'ACTIVO', 'ACTIVE'], true);
    }
}
