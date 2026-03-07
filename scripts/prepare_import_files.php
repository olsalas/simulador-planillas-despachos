<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$docsPath = $root.'/docs';
$sourceDriversPath = $docsPath.'/conductores_despachos.csv';
$sourceInvoicesPath = $docsPath.'/facturas.csv';
$sourceBranchMasterPaths = [
    $docsPath.'/puntos_dispensarios_07022026 (1).csv',
    $docsPath.'/droguerias_domicilios_07022026 (1).csv',
];
$generatedPath = $docsPath.'/generated';

if (! is_file($sourceDriversPath) || ! is_readable($sourceDriversPath)) {
    fwrite(STDERR, "Missing readable source file: {$sourceDriversPath}\n");
    exit(1);
}

if (! is_file($sourceInvoicesPath) || ! is_readable($sourceInvoicesPath)) {
    fwrite(STDERR, "Missing readable source file: {$sourceInvoicesPath}\n");
    exit(1);
}

if (! is_dir($generatedPath) && ! mkdir($generatedPath, 0775, true) && ! is_dir($generatedPath)) {
    fwrite(STDERR, "Unable to create output directory: {$generatedPath}\n");
    exit(1);
}

$invoiceRows = readCsv($sourceInvoicesPath);
$invoiceDriverNames = [];
$invoiceDriverIds = [];
$branches = [];
$branchMasterCodes = [];
$invoiceGroups = [];
$bodegaStats = [];
$driverStats = [];

foreach ($sourceBranchMasterPaths as $path) {
    if (! is_file($path) || ! is_readable($path)) {
        continue;
    }

    foreach (readCsv($path) as $row) {
        $normalized = normalizeBranchMasterRow($row);
        $code = $normalized['code'];

        if ($code === '') {
            continue;
        }

        $branchMasterCodes[$code] = true;
        $branches[$code] = isset($branches[$code])
            ? mergeBranchRows($branches[$code], $normalized)
            : $normalized;
    }
}

foreach ($invoiceRows as $row) {
    $driverExternalId = trim((string) ($row['nit_conductor'] ?? ''));
    $driverName = trim((string) ($row['nombre_conductor'] ?? ''));
    $serviceDate = normalizeServiceDate($row);
    $invoiceId = trim((string) ($row['id'] ?? ''));
    $bodegaCode = trim((string) ($row['bodega'] ?? ''));
    $cityName = trim((string) ($row['ciudad'] ?? ''));

    if ($driverExternalId !== '') {
        $invoiceDriverIds[$driverExternalId] = true;

        if ($driverName !== '' && ! isset($invoiceDriverNames[$driverExternalId])) {
            $invoiceDriverNames[$driverExternalId] = $driverName;
        }

        if (! isset($driverStats[$driverExternalId])) {
            $driverStats[$driverExternalId] = [
                'driver_name' => $driverName,
                'total_invoices' => 0,
                'bodega_counts' => [],
                'city_counts_by_bodega' => [],
            ];
        }

        if ($driverStats[$driverExternalId]['driver_name'] === '' && $driverName !== '') {
            $driverStats[$driverExternalId]['driver_name'] = $driverName;
        }

        $driverStats[$driverExternalId]['total_invoices']++;

        if ($bodegaCode !== '') {
            $driverStats[$driverExternalId]['bodega_counts'][$bodegaCode] = ($driverStats[$driverExternalId]['bodega_counts'][$bodegaCode] ?? 0) + 1;

            if ($cityName !== '') {
                if (! isset($driverStats[$driverExternalId]['city_counts_by_bodega'][$bodegaCode])) {
                    $driverStats[$driverExternalId]['city_counts_by_bodega'][$bodegaCode] = [];
                }

                $driverStats[$driverExternalId]['city_counts_by_bodega'][$bodegaCode][$cityName] = ($driverStats[$driverExternalId]['city_counts_by_bodega'][$bodegaCode][$cityName] ?? 0) + 1;
            }
        }
    }

    if ($bodegaCode !== '') {
        if (! isset($bodegaStats[$bodegaCode])) {
            $bodegaStats[$bodegaCode] = [
                'total_invoices' => 0,
                'city_counts' => [],
            ];
        }

        $bodegaStats[$bodegaCode]['total_invoices']++;

        if ($cityName !== '') {
            $bodegaStats[$bodegaCode]['city_counts'][$cityName] = ($bodegaStats[$bodegaCode]['city_counts'][$cityName] ?? 0) + 1;
        }
    }

    $branchCode = trim((string) ($row['nit'] ?? ''));
    if ($branchCode !== '') {
        $invoiceBranch = normalizeInvoiceBranchRow($row);
        $branches[$branchCode] = isset($branches[$branchCode])
            ? mergeBranchRows($branches[$branchCode], $invoiceBranch)
            : $invoiceBranch;
    }

    if ($driverExternalId === '' || $serviceDate === '' || $invoiceId === '') {
        continue;
    }

    $invoiceGroups[$driverExternalId.'|'.$serviceDate][] = [
        'external_invoice_id' => $invoiceId,
        'invoice_number' => trim((string) ($row['numeroFactura'] ?? '')),
        'driver_external_id' => $driverExternalId,
        'driver_name' => $driverName,
        'service_date' => $serviceDate,
        'branch_code' => $branchCode,
        'historical_latitude' => normalizeDecimal($row['latitud'] ?? null),
        'historical_longitude' => normalizeDecimal($row['longitud'] ?? null),
        'delivery_at' => normalizeDeliveryTimestamp($row),
    ];
}

$driverRows = [];
foreach (readCsv($sourceDriversPath) as $row) {
    $externalId = trim((string) ($row['cedula'] ?? ''));
    if ($externalId === '') {
        continue;
    }

    $isActive = strtoupper(trim((string) ($row['estado'] ?? ''))) === 'ACTIVO';
    $isReferencedByInvoice = isset($invoiceDriverIds[$externalId]);

    if (! $isActive && ! $isReferencedByInvoice) {
        continue;
    }

    $driverRows[$externalId] = [
        'external_id' => $externalId,
        'name' => trim((string) ($row['nombre'] ?? '')),
        'email' => '',
        'phone' => '',
    ];
}

foreach ($invoiceDriverIds as $externalId => $_) {
    if (isset($driverRows[$externalId])) {
        continue;
    }

    $driverRows[$externalId] = [
        'external_id' => $externalId,
        'name' => $invoiceDriverNames[$externalId] ?? 'Driver '.$externalId,
        'email' => '',
        'phone' => '',
    ];
}

ksort($driverRows, SORT_NATURAL);
ksort($branches, SORT_NATURAL);

$normalizedInvoices = [];

foreach ($invoiceGroups as $groupRows) {
    $withTimestamp = [];
    $withoutTimestamp = [];

    foreach ($groupRows as $row) {
        if ($row['delivery_at'] === '') {
            $row['historical_sequence'] = '';
            $withoutTimestamp[] = $row;
            continue;
        }

        $withTimestamp[] = $row;
    }

    usort($withTimestamp, static function (array $left, array $right): int {
        $timestampCompare = strcmp($left['delivery_at'], $right['delivery_at']);

        if ($timestampCompare !== 0) {
            return $timestampCompare;
        }

        return strcmp($left['external_invoice_id'], $right['external_invoice_id']);
    });

    foreach ($withTimestamp as $index => $row) {
        $row['historical_sequence'] = (string) ($index + 1);
        unset($row['delivery_at']);
        $normalizedInvoices[] = $row;
    }

    foreach ($withoutTimestamp as $row) {
        unset($row['delivery_at']);
        $normalizedInvoices[] = $row;
    }
}

usort($normalizedInvoices, static function (array $left, array $right): int {
    $serviceDateCompare = strcmp($left['service_date'], $right['service_date']);
    if ($serviceDateCompare !== 0) {
        return $serviceDateCompare;
    }

    $driverCompare = strcmp($left['driver_external_id'], $right['driver_external_id']);
    if ($driverCompare !== 0) {
        return $driverCompare;
    }

    $leftSequence = $left['historical_sequence'] === '' ? PHP_INT_MAX : (int) $left['historical_sequence'];
    $rightSequence = $right['historical_sequence'] === '' ? PHP_INT_MAX : (int) $right['historical_sequence'];
    $sequenceCompare = $leftSequence <=> $rightSequence;

    if ($sequenceCompare !== 0) {
        return $sequenceCompare;
    }

    return strcmp($left['external_invoice_id'], $right['external_invoice_id']);
});

writeCsv($generatedPath.'/drivers_import.csv', ['external_id', 'name', 'email', 'phone'], array_values($driverRows));
writeCsv($generatedPath.'/branches_seed.csv', ['code', 'name', 'address', 'latitude', 'longitude', 'is_active'], array_values($branches));
writeCsv($generatedPath.'/invoices_import.csv', [
    'external_invoice_id',
    'invoice_number',
    'driver_external_id',
    'driver_name',
    'service_date',
    'branch_code',
    'historical_sequence',
    'historical_latitude',
    'historical_longitude',
], $normalizedInvoices);
$depotCandidates = buildDepotCandidates(array_values($branches));
$depotsSeed = array_map(
    static fn (array $row): array => [
        'code' => $row['code'],
        'name' => $row['name'],
        'address' => $row['address'],
        'latitude' => $row['latitude'],
        'longitude' => $row['longitude'],
        'is_active' => $row['is_active'],
    ],
    $depotCandidates
);
$driverDepotReview = buildDriverDepotAssignmentReview($driverStats);
$driverDepotReady = array_values(array_filter(
    $driverDepotReview,
    static fn (array $row): bool => $row['ready_to_assign'] === '1'
));

writeCsv($generatedPath.'/depots_seed_candidates.csv', [
    'code',
    'name',
    'address',
    'latitude',
    'longitude',
    'is_active',
    'source_branch_code',
], $depotCandidates);
writeCsv($generatedPath.'/depots_seed.csv', [
    'code',
    'name',
    'address',
    'latitude',
    'longitude',
    'is_active',
], $depotsSeed);
writeCsv($generatedPath.'/driver_depot_candidates.csv', [
    'driver_external_id',
    'driver_name',
    'primary_bodega_code',
    'primary_city',
    'total_invoices',
    'primary_bodega_invoices',
    'primary_bodega_share_pct',
], buildDriverDepotCandidates($driverStats));
writeCsv($generatedPath.'/driver_depot_assignment_review.csv', [
    'driver_external_id',
    'driver_name',
    'primary_bodega_code',
    'primary_city',
    'primary_bodega_share_pct',
    'suggested_depot_code',
    'suggestion_confidence',
    'suggestion_reason',
    'ready_to_assign',
], $driverDepotReview);
writeCsv($generatedPath.'/driver_depot_assignment_ready.csv', [
    'driver_external_id',
    'depot_code',
], array_map(
    static fn (array $row): array => [
        'driver_external_id' => $row['driver_external_id'],
        'depot_code' => $row['suggested_depot_code'],
    ],
    $driverDepotReady
));

fwrite(STDOUT, "Generated files in docs/generated/\n");
fwrite(STDOUT, 'drivers_import.csv rows: '.count($driverRows)."\n");
fwrite(STDOUT, 'branches_seed.csv rows: '.count($branches)."\n");
fwrite(STDOUT, 'branches from master files: '.count($branchMasterCodes)."\n");
fwrite(STDOUT, 'invoices_import.csv rows: '.count($normalizedInvoices)."\n");
fwrite(STDOUT, 'depots_seed.csv rows: '.count($depotsSeed)."\n");
fwrite(STDOUT, 'driver_depot_assignment_ready.csv rows: '.count($driverDepotReady)."\n");
fwrite(STDOUT, 'bodega codes seen in invoices: '.count($bodegaStats)."\n");

/**
 * @return list<array<string, string>>
 */
function readCsv(string $path): array
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

/**
 * @param  list<string>  $headers
 * @param  list<array<string, string>>  $rows
 */
function writeCsv(string $path, array $headers, array $rows): void
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

function normalizeServiceDate(array $row): string
{
    $date = trim((string) ($row['fecha_planilla'] ?? ''));
    if ($date !== '') {
        return substr($date, 0, 10);
    }

    $dateTime = trim((string) ($row['fecha_planilla_datetime'] ?? ''));
    if ($dateTime === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($dateTime))->format('Y-m-d');
    } catch (Throwable) {
        return substr($dateTime, 0, 10);
    }
}

function normalizeDeliveryTimestamp(array $row): string
{
    $direct = trim((string) ($row['fecha_entrega_datetime'] ?? ''));
    if ($direct !== '') {
        return normalizeDateTimeValue($direct);
    }

    $date = trim((string) ($row['fecha_entrega'] ?? ''));
    $time = trim((string) ($row['hora_entrega'] ?? ''));

    if ($date === '' || $time === '') {
        return '';
    }

    return normalizeDateTimeValue($date.' '.$time);
}

function normalizeDateTimeValue(string $value): string
{
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $value;
    }
}

function normalizeDecimal(mixed $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    $value = str_replace(["\xc2\xa0", ' '], '', $value);
    $value = str_replace(',', '.', $value);

    return is_numeric($value) ? $value : '';
}

/**
 * @param  array<string, string>  $row
 * @return array{code: string, name: string, address: string, latitude: string, longitude: string, is_active: string}
 */
function normalizeBranchMasterRow(array $row): array
{
    return [
        'code' => trim((string) ($row['codigo'] ?? '')),
        'name' => trim((string) ($row['nombre'] ?? '')),
        'address' => trim((string) ($row['direccion'] ?? '')),
        'latitude' => normalizeDecimal($row['latitud'] ?? null),
        'longitude' => normalizeDecimal($row['longitud'] ?? null),
        'is_active' => normalizeActiveFlag($row['estado'] ?? '1'),
    ];
}

/**
 * @param  array<string, string>  $row
 * @return array{code: string, name: string, address: string, latitude: string, longitude: string, is_active: string}
 */
function normalizeInvoiceBranchRow(array $row): array
{
    return [
        'code' => trim((string) ($row['nit'] ?? '')),
        'name' => trim((string) ($row['nombres'] ?? '')),
        'address' => trim((string) ($row['direccion'] ?? '')),
        'latitude' => normalizeDecimal($row['latitud'] ?? null),
        'longitude' => normalizeDecimal($row['longitud'] ?? null),
        'is_active' => '1',
    ];
}

/**
 * @param  array{code: string, name: string, address: string, latitude: string, longitude: string, is_active: string}  $current
 * @param  array{code: string, name: string, address: string, latitude: string, longitude: string, is_active: string}  $candidate
 * @return array{code: string, name: string, address: string, latitude: string, longitude: string, is_active: string}
 */
function mergeBranchRows(array $current, array $candidate): array
{
    return [
        'code' => $current['code'] !== '' ? $current['code'] : $candidate['code'],
        'name' => pickFilled($current['name'], $candidate['name']),
        'address' => pickFilled($current['address'], $candidate['address']),
        'latitude' => pickFilled($current['latitude'], $candidate['latitude']),
        'longitude' => pickFilled($current['longitude'], $candidate['longitude']),
        'is_active' => $current['is_active'] === '1' || $candidate['is_active'] === '1' ? '1' : '0',
    ];
}

function normalizeActiveFlag(mixed $value): string
{
    $normalized = strtoupper(trim((string) $value));

    return in_array($normalized, ['1', 'ACTIVO', 'ACTIVE', 'TRUE'], true) ? '1' : '0';
}

/**
 * @param  list<array{code: string, name: string, address: string, latitude: string, longitude: string, is_active: string}>  $branches
 * @return list<array<string, string>>
 */
function buildDepotCandidates(array $branches): array
{
    $rows = [];

    foreach ($branches as $branch) {
        $name = trim($branch['name']);
        if ($name === '' || ! str_starts_with(strtoupper($name), 'BODEGA')) {
            continue;
        }

        $rows[] = [
            'code' => 'DEPOT-'.$branch['code'],
            'name' => $branch['name'],
            'address' => $branch['address'],
            'latitude' => $branch['latitude'],
            'longitude' => $branch['longitude'],
            'is_active' => $branch['is_active'],
            'source_branch_code' => $branch['code'],
        ];
    }

    usort($rows, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

    return $rows;
}

/**
 * @param  array<string, array{driver_name: string, total_invoices: int, bodega_counts: array<string, int>, city_counts_by_bodega: array<string, array<string, int>>}>  $driverStats
 * @return list<array<string, string>>
 */
function buildDriverDepotCandidates(array $driverStats): array
{
    $rows = [];

    foreach ($driverStats as $driverExternalId => $stats) {
        $primaryBodega = '';
        $primaryCount = 0;

        foreach ($stats['bodega_counts'] as $bodegaCode => $count) {
            if ($count > $primaryCount || ($count === $primaryCount && strcmp($bodegaCode, $primaryBodega) < 0)) {
                $primaryBodega = $bodegaCode;
                $primaryCount = $count;
            }
        }

        $primaryCity = '';
        if ($primaryBodega !== '' && isset($stats['city_counts_by_bodega'][$primaryBodega])) {
            foreach ($stats['city_counts_by_bodega'][$primaryBodega] as $cityName => $count) {
                if ($primaryCity === '' || $count > ($stats['city_counts_by_bodega'][$primaryBodega][$primaryCity] ?? 0)) {
                    $primaryCity = $cityName;
                }
            }
        }

        $share = $stats['total_invoices'] > 0
            ? number_format(($primaryCount / $stats['total_invoices']) * 100, 2, '.', '')
            : '0.00';

        $rows[] = [
            'driver_external_id' => $driverExternalId,
            'driver_name' => $stats['driver_name'] !== '' ? $stats['driver_name'] : 'Driver '.$driverExternalId,
            'primary_bodega_code' => $primaryBodega,
            'primary_city' => $primaryCity,
            'total_invoices' => (string) $stats['total_invoices'],
            'primary_bodega_invoices' => (string) $primaryCount,
            'primary_bodega_share_pct' => $share,
        ];
    }

    usort(
        $rows,
        static fn (array $left, array $right): int => strcmp(
            (string) $left['driver_external_id'],
            (string) $right['driver_external_id']
        )
    );

    return $rows;
}

/**
 * @param  array<string, array{driver_name: string, total_invoices: int, bodega_counts: array<string, int>, city_counts_by_bodega: array<string, array<string, int>>}>  $driverStats
 * @return list<array<string, string>>
 */
function buildDriverDepotAssignmentReview(array $driverStats): array
{
    $explicitMap = [
        '30' => 'DEPOT-17002',
        '201' => 'DEPOT-1001',
        '550' => 'DEPOT-550',
        '560' => 'DEPOT-14006',
        '60' => 'DEPOT-2030',
    ];

    $rows = [];

    foreach (buildDriverDepotCandidates($driverStats) as $candidate) {
        $bodegaCode = (string) $candidate['primary_bodega_code'];
        $sharePct = (float) $candidate['primary_bodega_share_pct'];
        $suggestedDepotCode = $explicitMap[$bodegaCode] ?? '';
        $confidence = '';
        $reason = '';
        $readyToAssign = '0';

        if ($suggestedDepotCode !== '') {
            $confidence = $sharePct >= 70 ? 'high' : 'medium';
            $reason = 'explicit_bodega_to_depot_map';
            $readyToAssign = $sharePct >= 70 ? '1' : '0';
        } else {
            $confidence = 'none';
            $reason = 'requires_manual_mapping';
        }

        $rows[] = [
            'driver_external_id' => (string) $candidate['driver_external_id'],
            'driver_name' => (string) $candidate['driver_name'],
            'primary_bodega_code' => $bodegaCode,
            'primary_city' => (string) $candidate['primary_city'],
            'primary_bodega_share_pct' => (string) $candidate['primary_bodega_share_pct'],
            'suggested_depot_code' => $suggestedDepotCode,
            'suggestion_confidence' => $confidence,
            'suggestion_reason' => $reason,
            'ready_to_assign' => $readyToAssign,
        ];
    }

    return $rows;
}

function pickFilled(string $current, string $candidate): string
{
    return $current !== '' ? $current : $candidate;
}
