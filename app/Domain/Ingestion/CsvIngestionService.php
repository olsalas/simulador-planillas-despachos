<?php

namespace App\Domain\Ingestion;

use App\Models\Branch;
use App\Models\Driver;
use App\Models\IngestionBatch;
use App\Models\IngestionRow;
use App\Models\Invoice;
use App\Models\RouteBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class CsvIngestionService
{
    public function __construct(
        private readonly ConsolidateRouteBatchesService $consolidateRouteBatchesService,
    ) {
    }

    /**
     * @param  'invoices'|'drivers'  $type
     * @return array<string, mixed>
     */
    public function import(UploadedFile $file, string $type, ?int $uploadedBy): array
    {
        return DB::transaction(function () use ($file, $type, $uploadedBy): array {
            $batch = IngestionBatch::create([
                'type' => $type,
                'original_filename' => $file->getClientOriginalName(),
                'uploaded_by' => $uploadedBy,
                'status' => 'processing',
            ]);

            $totalRows = 0;
            $validRows = 0;
            $invalidRows = 0;
            $invalidSamples = [];
            $affectedKeys = [];

            foreach ($this->rowsFromCsv($file) as [$lineNumber, $row]) {
                $totalRows++;

                $validation = Validator::make($row, $this->rulesFor($type));

                if ($validation->fails()) {
                    $invalidRows++;

                    $errors = $validation->errors()->toArray();
                    if (count($invalidSamples) < 10) {
                        $invalidSamples[] = [
                            'row_number' => $lineNumber,
                            'errors' => $errors,
                        ];
                    }

                    IngestionRow::create([
                        'ingestion_batch_id' => $batch->id,
                        'row_number' => $lineNumber,
                        'status' => 'invalid',
                        'raw_payload' => $row,
                        'validation_errors' => $errors,
                    ]);

                    continue;
                }

                $validRows++;

                IngestionRow::create([
                    'ingestion_batch_id' => $batch->id,
                    'row_number' => $lineNumber,
                    'status' => 'valid',
                    'raw_payload' => $row,
                    'validation_errors' => null,
                ]);

                if ($type === 'drivers') {
                    $this->persistDriverRow($row);
                    continue;
                }

                $affectedKey = $this->persistInvoiceRow($row, $batch);
                if ($affectedKey !== null) {
                    $affectedKeys[$affectedKey] = true;
                }
            }

            $affectedBatchIds = $type === 'invoices'
                ? $this->consolidateRouteBatchesService->consolidate(array_keys($affectedKeys), $batch->id)
                : [];

            $batch->update([
                'status' => $invalidRows > 0 ? 'processed_with_errors' : 'processed',
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
                'summary' => [
                    'invalid_samples' => $invalidSamples,
                    'affected_route_batches' => $affectedBatchIds,
                ],
            ]);

            return [
                'id' => $batch->id,
                'type' => $batch->type,
                'status' => $batch->status,
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
                'invalid_samples' => $invalidSamples,
                'affected_route_batches' => $affectedBatchIds,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesFor(string $type): array
    {
        if ($type === 'drivers') {
            return [
                'external_id' => ['required', 'string', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'email'],
                'phone' => ['nullable', 'string', 'max:255'],
            ];
        }

        return [
            'external_invoice_id' => ['required', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'driver_external_id' => ['required', 'string', 'max:255'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'service_date' => ['required', 'date_format:Y-m-d'],
            'branch_code' => ['required', 'string', 'max:255'],
            'historical_sequence' => ['nullable', 'integer'],
            'historical_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'historical_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function persistDriverRow(array $row): void
    {
        Driver::updateOrCreate(
            ['external_id' => (string) $row['external_id']],
            [
                'name' => (string) $row['name'],
                'email' => $this->nullableString($row['email'] ?? null),
                'phone' => $this->nullableString($row['phone'] ?? null),
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function persistInvoiceRow(array $row, IngestionBatch $batch): ?string
    {
        $driverExternalId = (string) $row['driver_external_id'];
        $driverName = $this->nullableString($row['driver_name'] ?? null);

        $driver = Driver::firstOrCreate(
            ['external_id' => $driverExternalId],
            ['name' => $driverName ?: 'Driver '.$driverExternalId, 'is_active' => true]
        );

        if ($driverName !== null && $driver->name !== $driverName) {
            $driver->update(['name' => $driverName]);
        }

        $branch = Branch::query()
            ->where('code', (string) $row['branch_code'])
            ->first();

        $status = 'ready';
        $outlierReason = null;
        $branchId = $branch?->id;

        if ($branch === null) {
            $status = 'pending';
            $outlierReason = 'branch_not_found';
        } elseif ($branch->latitude === null || $branch->longitude === null) {
            $status = 'pending';
            $outlierReason = 'branch_without_geocode';
        }

        Invoice::updateOrCreate(
            [
                'external_invoice_id' => (string) $row['external_invoice_id'],
                'service_date' => (string) $row['service_date'],
            ],
            [
                'ingestion_batch' => (string) $batch->id,
                'invoice_number' => $this->nullableString($row['invoice_number'] ?? null),
                'driver_id' => $driver->id,
                'branch_id' => $branchId,
                'historical_sequence' => $this->nullableInteger($row['historical_sequence'] ?? null),
                'historical_latitude' => $this->nullableFloat($row['historical_latitude'] ?? null),
                'historical_longitude' => $this->nullableFloat($row['historical_longitude'] ?? null),
                'status' => $status,
                'outlier_reason' => $outlierReason,
                'raw_payload' => $row,
            ]
        );

        return $driver->id.'|'.$row['service_date'];
    }

    /**
     * @return \Generator<int, array{0: int, 1: array<string, string|null>}, mixed, void>
     */
    private function rowsFromCsv(UploadedFile $file): \Generator
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false) {
            fclose($handle);
            throw new RuntimeException('CSV file is empty.');
        }

        $headers = $this->normalizeHeaders($headerRow);
        $lineNumber = 1;

        while (($csvLine = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if ($this->isEmptyCsvLine($csvLine)) {
                continue;
            }

            $payload = [];
            foreach ($headers as $index => $header) {
                $payload[$header] = $this->nullableString($csvLine[$index] ?? null);
            }

            yield [$lineNumber, $payload];
        }

        fclose($handle);
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header, int $index): string {
            $header = (string) $header;

            if ($index === 0) {
                $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
            }

            return strtolower(trim($header));
        }, $headers, array_keys($headers));
    }

    /**
     * @param  list<string|null>  $line
     */
    private function isEmptyCsvLine(array $line): bool
    {
        foreach ($line as $cell) {
            if ($this->nullableString($cell) !== null) {
                return false;
            }
        }

        return true;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInteger(mixed $value): ?int
    {
        $string = $this->nullableString($value);

        return $string === null ? null : (int) $string;
    }

    private function nullableFloat(mixed $value): ?float
    {
        $string = $this->nullableString($value);

        return $string === null ? null : (float) $string;
    }
}
