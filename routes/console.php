<?php

use App\Domain\Ingestion\ConsolidateRouteBatchesService;
use App\Domain\Ingestion\CsvIngestionService;
use App\Domain\Planning\BogotaScope;
use App\Models\Branch;
use App\Models\Depot;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\InvoiceStop;
use App\Models\PlanningScenario;
use App\Models\RouteBatch;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('demo:load-generated-data {--skip-invoices : Skip importing invoice history} {--invoice-chunk-size=1000 : Number of invoice rows per import chunk} {--all-cities : Disable the Bogotá-only safety filter and load every row from docs/generated}', function (CsvIngestionService $csvIngestionService, BogotaScope $bogotaScope) {
    $generatedPath = base_path('docs/generated');
    $paths = [
        'branches' => $generatedPath.'/branches_seed.csv',
        'depots' => $generatedPath.'/depots_seed.csv',
        'drivers' => $generatedPath.'/drivers_import.csv',
        'assignments' => $generatedPath.'/driver_depot_assignment_ready.csv',
        'invoices' => $generatedPath.'/invoices_import.csv',
    ];

    foreach ([$paths['branches'], $paths['depots'], $paths['drivers'], $paths['assignments']] as $requiredPath) {
        if (! is_file($requiredPath) || ! is_readable($requiredPath)) {
            $this->error('Missing generated file: '.$requiredPath);

            return 1;
        }
    }

    if (! $this->option('skip-invoices') && (! is_file($paths['invoices']) || ! is_readable($paths['invoices']))) {
        $this->error('Missing generated file: '.$paths['invoices']);

        return 1;
    }

    $temporaryPaths = [];
    $filteredSummary = null;

    if (! $this->option('all-cities')) {
        $filtered = demoBuildBogotaGeneratedSnapshot($paths, $bogotaScope, ! $this->option('skip-invoices'));
        $paths = $filtered['paths'];
        $temporaryPaths = $filtered['temporary_paths'];
        $filteredSummary = $filtered['summary'];

        $this->line('Applying Bogotá-only safety filter to generated CSVs before import.');
    }

    try {
        $branchStats = demoLoadBranches($paths['branches']);
        $depotStats = demoLoadDepots($paths['depots']);

        $driverReport = $csvIngestionService->import(
            demoMakeUploadedFile($paths['drivers']),
            'drivers',
            null,
        );

        $driverDepotStats = demoApplyDriverDepotAssignments($paths['assignments']);

        $invoiceReport = null;
        if (! $this->option('skip-invoices')) {
            $invoiceReport = demoImportInvoicesInChunks(
                $csvIngestionService,
                $paths['invoices'],
                max(1, (int) $this->option('invoice-chunk-size')),
                $this,
            );
        }
    } finally {
        demoCleanupTemporaryFiles($temporaryPaths);
    }

    $this->info('Generated demo data loaded.');
    $this->newLine();

    if ($filteredSummary !== null) {
        $this->line('Bogotá-only source rows:');
        $this->line('- branches: '.$filteredSummary['branches']);
        $this->line('- depots: '.$filteredSummary['depots']);
        $this->line('- drivers: '.$filteredSummary['drivers']);
        $this->line('- driver assignments: '.$filteredSummary['assignments']);
        if (! $this->option('skip-invoices')) {
            $this->line('- invoices: '.$filteredSummary['invoices']);
        }
        $this->newLine();
    }

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
})->purpose('Load generated demo data into the database, defaulting to a Bogotá-only safety filter');

Artisan::command('demo:prune-non-bogota {--force : Apply deletions. Without this option the command only reports what would be removed}', function (BogotaScope $bogotaScope, ConsolidateRouteBatchesService $consolidateRouteBatchesService) {
    $keep = demoBuildBogotaKeepSet($bogotaScope);

    $summary = [
        'depots_to_delete' => demoCountOutsideIds(Depot::query(), $keep['depot_ids']),
        'drivers_to_delete' => demoCountOutsideIds(Driver::query(), $keep['driver_ids']),
        'branches_to_delete' => demoCountOutsideIds(Branch::query(), $keep['branch_ids']),
        'invoices_to_delete' => demoCountOutsideIds(Invoice::query(), $keep['invoice_ids']),
        'route_batches_to_rebuild' => RouteBatch::count(),
        'invoice_stops_to_rebuild' => InvoiceStop::count(),
        'planning_scenarios_to_reset' => PlanningScenario::count(),
    ];

    $this->info('Bogotá-only cleanup preview');
    $this->line('Keep set:');
    $this->line('- depots: '.count($keep['depot_ids']));
    $this->line('- drivers: '.count($keep['driver_ids']));
    $this->line('- branches: '.count($keep['branch_ids']));
    $this->line('- invoices: '.count($keep['invoice_ids']));
    $this->newLine();
    $this->line('Rows to remove/reset:');
    $this->line('- depots: '.$summary['depots_to_delete']);
    $this->line('- drivers: '.$summary['drivers_to_delete']);
    $this->line('- branches: '.$summary['branches_to_delete']);
    $this->line('- invoices: '.$summary['invoices_to_delete']);
    $this->line('- route_batches: '.$summary['route_batches_to_rebuild']);
    $this->line('- invoice_stops: '.$summary['invoice_stops_to_rebuild']);
    $this->line('- planning_scenarios: '.$summary['planning_scenarios_to_reset']);

    if (! $this->option('force')) {
        $this->newLine();
        $this->warn('Dry run only. Re-run with --force to delete non-Bogotá data and rebuild derived tables from the remaining Bogotá dataset.');

        return 0;
    }

    $rebuiltRouteBatchIds = [];

    DB::transaction(function () use ($keep, $consolidateRouteBatchesService, &$rebuiltRouteBatchIds): void {
        PlanningScenario::query()->delete();
        RouteBatch::query()->delete();
        InvoiceStop::query()->delete();

        demoDeleteOutsideIds(Invoice::query(), $keep['invoice_ids']);
        demoDeleteOutsideIds(Driver::query(), $keep['driver_ids']);
        demoDeleteOutsideIds(Depot::query(), $keep['depot_ids']);
        demoDeleteOutsideIds(Branch::query(), $keep['branch_ids']);

        $rebuiltRouteBatchIds = $consolidateRouteBatchesService->rebuildAll();
    });

    $this->newLine();
    $this->info('Bogotá-only cleanup applied.');
    $this->line('Rebuilt route batches: '.count($rebuiltRouteBatchIds));
    $this->line('Current totals:');
    $this->line('- branches: '.Branch::count());
    $this->line('- depots: '.Depot::count());
    $this->line('- drivers: '.Driver::count());
    $this->line('- invoices: '.Invoice::count());
    $this->line('- route_batches: '.RouteBatch::count());
    $this->line('- invoice_stops: '.InvoiceStop::count());
    $this->line('- planning_scenarios: '.PlanningScenario::count());

    return 0;
})->purpose('Delete non-Bogotá demo data safely and rebuild derived tables from the remaining Bogotá dataset');

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

if (! function_exists('demoWriteCsv')) {
    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    function demoWriteCsv(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to write '.$path);
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($handle, $line);
        }

        fclose($handle);
    }
}

if (! function_exists('demoBuildBogotaGeneratedSnapshot')) {
    /**
     * @param  array{branches: string, depots: string, drivers: string, assignments: string, invoices: string}  $paths
     * @return array{
     *     paths: array{branches: string, depots: string, drivers: string, assignments: string, invoices: string},
     *     temporary_paths: list<string>,
     *     summary: array{branches: int, depots: int, drivers: int, assignments: int, invoices: int}
     * }
     */
    function demoBuildBogotaGeneratedSnapshot(array $paths, BogotaScope $bogotaScope, bool $includeInvoices): array
    {
        $depotRows = array_values(array_filter(
            demoReadCsv($paths['depots']),
            static fn (array $row): bool => demoMatchesBogotaCsvRow($row, $bogotaScope)
        ));
        $depotCodes = array_fill_keys(array_map(static fn (array $row): string => trim((string) ($row['code'] ?? '')), $depotRows), true);

        $branchRows = array_values(array_filter(
            demoReadCsv($paths['branches']),
            static fn (array $row): bool => demoMatchesBogotaCsvRow($row, $bogotaScope)
        ));
        $branchCodes = array_fill_keys(array_map(static fn (array $row): string => trim((string) ($row['code'] ?? '')), $branchRows), true);

        $assignmentRows = array_values(array_filter(
            demoReadCsv($paths['assignments']),
            static fn (array $row): bool => isset($depotCodes[trim((string) ($row['depot_code'] ?? ''))])
        ));
        $driverIds = array_fill_keys(array_map(static fn (array $row): string => trim((string) ($row['driver_external_id'] ?? '')), $assignmentRows), true);

        $driverRows = array_values(array_filter(
            demoReadCsv($paths['drivers']),
            static fn (array $row): bool => isset($driverIds[trim((string) ($row['external_id'] ?? ''))])
        ));

        $invoiceRows = [];
        if ($includeInvoices) {
            $invoiceRows = array_values(array_filter(
                demoReadCsv($paths['invoices']),
                static fn (array $row): bool => isset($driverIds[trim((string) ($row['driver_external_id'] ?? ''))])
                    && isset($branchCodes[trim((string) ($row['branch_code'] ?? ''))])
            ));
        }

        $temporaryPaths = [];
        $filteredPaths = [
            'branches' => demoWriteTemporaryCsv('demo_branches_', ['code', 'name', 'address', 'latitude', 'longitude', 'is_active'], $branchRows, $temporaryPaths),
            'depots' => demoWriteTemporaryCsv('demo_depots_', ['code', 'name', 'address', 'latitude', 'longitude', 'is_active'], $depotRows, $temporaryPaths),
            'drivers' => demoWriteTemporaryCsv('demo_drivers_', ['external_id', 'name', 'email', 'phone'], $driverRows, $temporaryPaths),
            'assignments' => demoWriteTemporaryCsv('demo_assignments_', ['driver_external_id', 'depot_code'], $assignmentRows, $temporaryPaths),
            'invoices' => $paths['invoices'],
        ];

        if ($includeInvoices) {
            $filteredPaths['invoices'] = demoWriteTemporaryCsv(
                'demo_invoices_filtered_',
                ['external_invoice_id', 'invoice_number', 'driver_external_id', 'driver_name', 'service_date', 'branch_code', 'historical_sequence', 'historical_latitude', 'historical_longitude'],
                $invoiceRows,
                $temporaryPaths,
            );
        }

        return [
            'paths' => $filteredPaths,
            'temporary_paths' => $temporaryPaths,
            'summary' => [
                'branches' => count($branchRows),
                'depots' => count($depotRows),
                'drivers' => count($driverRows),
                'assignments' => count($assignmentRows),
                'invoices' => count($invoiceRows),
            ],
        ];
    }
}

if (! function_exists('demoWriteTemporaryCsv')) {
    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $temporaryPaths
     */
    function demoWriteTemporaryCsv(string $prefix, array $headers, array $rows, array &$temporaryPaths): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('Unable to create temporary CSV file.');
        }

        demoWriteCsv($path, $headers, $rows);
        $temporaryPaths[] = $path;

        return $path;
    }
}

if (! function_exists('demoCleanupTemporaryFiles')) {
    /**
     * @param  list<string>  $paths
     */
    function demoCleanupTemporaryFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }
}

if (! function_exists('demoMatchesBogotaCsvRow')) {
    function demoMatchesBogotaCsvRow(array $row, BogotaScope $bogotaScope): bool
    {
        return $bogotaScope->matchesLocation(
            $row['latitude'] ?? null,
            $row['longitude'] ?? null,
            $row['name'] ?? null,
            $row['address'] ?? null,
            $row['code'] ?? null,
        );
    }
}

if (! function_exists('demoBuildBogotaKeepSet')) {
    /**
     * @return array{depot_ids: list<int>, driver_ids: list<int>, branch_ids: list<int>, invoice_ids: list<int>}
     */
    function demoBuildBogotaKeepSet(BogotaScope $bogotaScope): array
    {
        $depotIds = Depot::query()
            ->get()
            ->filter(fn (Depot $depot): bool => $bogotaScope->isBogotaDepot($depot))
            ->modelKeys();

        $branchIds = Branch::query()
            ->get()
            ->filter(fn (Branch $branch): bool => $bogotaScope->matchesLocation(
                $branch->latitude,
                $branch->longitude,
                $branch->name,
                $branch->address,
                $branch->code,
            ))
            ->modelKeys();

        $driverIds = $depotIds === []
            ? []
            : Driver::query()
                ->whereIn('depot_id', $depotIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

        $invoiceIds = ($driverIds === [] || $branchIds === [])
            ? []
            : Invoice::query()
                ->whereIn('driver_id', $driverIds)
                ->whereIn('branch_id', $branchIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

        return [
            'depot_ids' => array_map('intval', $depotIds),
            'driver_ids' => $driverIds,
            'branch_ids' => array_map('intval', $branchIds),
            'invoice_ids' => $invoiceIds,
        ];
    }
}

if (! function_exists('demoCountOutsideIds')) {
    function demoCountOutsideIds($query, array $ids): int
    {
        return $ids === []
            ? (int) $query->count()
            : (int) $query->whereNotIn('id', $ids)->count();
    }
}

if (! function_exists('demoDeleteOutsideIds')) {
    function demoDeleteOutsideIds($query, array $ids): int
    {
        return $ids === []
            ? (int) $query->delete()
            : (int) $query->whereNotIn('id', $ids)->delete();
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
