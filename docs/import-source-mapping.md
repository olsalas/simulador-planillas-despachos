# Source Import Mapping

## Decision on date vs datetime

For the current MVP, `service_date` should remain a `date`, not a `datetime`.

Reasoning:
- The main planning unit in this system is `driver + day`.
- `route_batches` are unique by `driver_id + service_date`.
- The comparison screen answers "how this day was planned" vs "how it could have been planned better".

What the source datetimes are still useful for:
- deriving `historical_sequence`
- future audit or SLA analysis

What they are not needed for right now:
- the main key of the journey
- grouping the route batch

Recommended rule:
- `service_date` <- `fecha_planilla`
- if `fecha_planilla` is not present, use `date(fecha_planilla_datetime)`

Do not overload `service_date` with time.
If later we need intraday analysis, add dedicated fields such as:
- `historical_planned_at`
- `historical_delivered_at`

## Driver source mapping

Source file reviewed:
- `docs/conductores_despachos.csv`

Recommended target mapping:

| Target field | Source field | Notes |
| --- | --- | --- |
| `external_id` | `cedula` | Use this, not source `id`. The invoice extract references the driver by `nit_conductor`, which matches `cedula`. |
| `name` | `nombre` | Keep the display name. |
| `email` | none | Leave empty for now. |
| `phone` | none | Leave empty for now. |

Source fields not needed in the current importer:
- `id`
- `password`
- `estado`
- `isLogin`
- `lastLogin`
- `latitud`
- `longitud`
- `distrito`
- `created_at`
- `updated_at`
- `token`
- `tipo_vehiculo`
- `empresa`
- `area`

Operational note:
- Because the current `drivers` import does not accept an active/inactive flag, only load active drivers into the target CSV, or accept that all imported drivers will become active in this MVP.

Suggested filter before exporting to the target file:
- `estado = ACTIVO`

## Invoice source mapping

Source file reviewed:
- `docs/facturas.csv`

Recommended target mapping:

| Target field | Source field | Notes |
| --- | --- | --- |
| `external_invoice_id` | `id` | Best stable row identifier from the source extract. |
| `invoice_number` | `numeroFactura` | Business-facing invoice number. |
| `driver_external_id` | `nit_conductor` | Must match the driver `external_id`. |
| `driver_name` | `nombre_conductor` | Helps create/update the driver display name. |
| `service_date` | `fecha_planilla` | Use the route day, not invoice creation time. |
| `branch_code` | `nit` | This should match a preloaded branch code in the target system. |
| `historical_sequence` | derived | Sort by actual delivery timestamp within `driver_external_id + service_date`. |
| `historical_latitude` | `latitud` | Optional. Stored for audit. |
| `historical_longitude` | `longitud` | Optional. Stored for audit. |

## Branch source mapping

Preferred source files reviewed:
- `docs/puntos_dispensarios_07022026 (1).csv`
- `docs/droguerias_domicilios_07022026 (1).csv`

Fallback source:
- `docs/facturas.csv`

Recommended target mapping for `branches`:

| Target field | Preferred source field | Fallback source field | Notes |
| --- | --- | --- | --- |
| `code` | `codigo` | `nit` | Use the master point code when available. |
| `name` | `nombre` | `nombres` | Prefer the dedicated point master name. |
| `address` | `direccion` | `direccion` | Same semantic meaning. |
| `latitude` | `latitud` | `latitud` | Prefer master coordinates. |
| `longitude` | `longitud` | `longitud` | Prefer master coordinates. |
| `is_active` | `estado` | fixed `1` | In the invoice fallback, assume active because there is no active flag. |

Important:
- The new point master files are better sources for `branches` than the invoice extract.
- They still do not cover every `branch_code` referenced by invoices in the sample.
- Because of that, the generation script builds `branches_seed.csv` as a union:
  - first from point master files,
  - then completing missing codes from invoices.

## Depot strategy

Do not model `depot` as plain city.

Recommended concept:
- `depot` = physical warehouse or origin point
- `city` = descriptive geography

Why:
- the routing engine needs an origin coordinate
- the same city can have more than one depot
- the same operational `bodega` code in invoices can appear across many cities, so it is not always a stable physical origin by itself

For the current scalable approach:
- generate `depots_seed_candidates.csv` from master points whose names clearly start with `BODEGA`
- generate `depots_seed.csv` as the initial depot master compatible with the app
- generate `driver_depot_candidates.csv` from the dominant `bodega` observed per driver in invoices
- generate `driver_depot_assignment_review.csv` and `driver_depot_assignment_ready.csv` for assignment triage
- use those files to curate the real depot master and the driver-to-depot assignment

This avoids a false shortcut such as `city = depot`.

## How to derive `historical_sequence`

The source sample does not contain an explicit route sequence column.
For this MVP, derive it from the observed delivery order.

Recommended derivation:
1. Partition by `nit_conductor + fecha_planilla`
2. Sort by:
   - `fecha_entrega_datetime` ascending
   - then `id` ascending for tie-break
3. Assign `1..N`

Fallback if `fecha_entrega_datetime` is empty:
1. Combine `fecha_entrega + hora_entrega`
2. If that is also missing, leave `historical_sequence` empty

Important:
- If `historical_sequence` is empty, the stop can still enter the suggested route if it has geocode, but it will be shown as `non_comparable` in the historical comparison.

## Fields from source invoices that are useful later but not part of the MVP import

Not loaded right now:
- `numero_planilla`
- `numero_desp`
- `fec_fac`
- `fecha_factura`
- `fecha_factura_datetime`
- `hora_factura`
- `fecha_planilla_datetime`
- `hora_planilla`
- `fecha_entrega`
- `hora_entrega`
- `fecha_entrega_datetime`
- `estado`
- `novedad`
- `placa`
- `tipoMovimiento`
- `distancia_km_entrega`
- `fuera_rango`
- `isTest`
- `fecha_recibido_logisticko`
- transport time columns

These may be valuable later for:
- actual vs suggested comparison
- SLA analysis
- route quality scoring
- zonification analysis

## Preconditions for a useful import

Before importing target `invoices`, the system should already have:
- `branches` loaded with `code`, `name`, `latitude`, `longitude`
- at least one `depot` with geocode

Why:
- `branch_code` in the invoice file must match an existing branch
- if a branch is missing or has no geocode, the invoice is imported as `pending`
- the route comparison uses official branch geocodes, not the source invoice coordinates

## Suggested target files

Use these normalized target files:
- `docs/templates/depots_template.csv`
- `docs/templates/driver_depot_assignment_template.csv`
- `docs/templates/branches_template.csv`
- `docs/templates/drivers_template.csv`
- `docs/templates/invoices_template.csv`

## Reproducible local generation from source extracts

To transform the source extracts already placed in `docs/`, use:

```bash
./vendor/bin/sail php scripts/prepare_import_files.php
```

This generates local files in `docs/generated/`:
- `drivers_import.csv`
- `branches_seed.csv`
- `invoices_import.csv`
- `depots_seed_candidates.csv`
- `depots_seed.csv`
- `driver_depot_candidates.csv`
- `driver_depot_assignment_review.csv`
- `driver_depot_assignment_ready.csv`

These generated files are intentionally ignored by Git.

Important:
- `drivers_import.csv` includes active drivers plus any driver referenced by invoices, even if not currently active in the source extract.
- `branches_seed.csv` uses point master files as the preferred branch source and completes missing branch codes from invoices.
- `invoices_import.csv` derives `historical_sequence` from delivery timestamps.
- `depots_seed_candidates.csv` is a traceable candidate list of warehouse-like points.
- `depots_seed.csv` is the first importable depot master proposal.
- `driver_depot_candidates.csv` is a candidate assignment helper based on the dominant `bodega` per driver.
- `driver_depot_assignment_review.csv` contains all drivers with explicit or unresolved mapping status.
- `driver_depot_assignment_ready.csv` contains only the drivers with a high-confidence assignment suggestion.
