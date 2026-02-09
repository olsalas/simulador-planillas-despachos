# Ruteo

MVP para **ingesta de históricos de facturación** y **simulación de rutas por conductor + día**.

La app permite:
1. Cargar CSV de conductores y facturas.
2. Consolidar lotes (`route_batches`) y paradas (`invoice_stops`).
3. Simular una ruta en mapa con proveedor real (HERE) o mock.

## Stack técnico

- Backend: `Laravel 12`, `PHP 8.2+`.
- Frontend: `Inertia.js 2`, `Vue 3`, `Vite`, `Tailwind CSS`.
- Persistencia: `PostgreSQL` (Sail) o `SQLite` (local rápido/testing).
- Cache/colas en local Docker: `Redis`.
- Mapa: `maplibre-gl` con tiles OSM.

## Flujo funcional

1. `POST /dashboard/upload-csv` procesa un CSV (`drivers` o `invoices`).
2. `CsvIngestionService` valida fila por fila y persiste `ingestion_batches` + `ingestion_rows`.
3. Para facturas, se actualizan `invoices`, se clasifican outliers y se consolida `route_batches` + `invoice_stops`.
4. `POST /dashboard/simulate/preview` construye la ruta para un batch usando `BuildSimulationRouteService`.
5. El servicio resuelve depósito, ejecuta provider (`here` o `mock`), aplica cache y devuelve geometría + métricas.

## Rutas de UI principales

- `/dashboard`
- `/dashboard/upload-csv`
- `/dashboard/batches`
- `/dashboard/batches/{routeBatch}`
- `/dashboard/simulate`

## Inicio rápido

### Opción recomendada: Sail (Docker)

```bash
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

Abrir: `http://localhost`.

### Opción local sin Docker

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate
composer run dev
```

## Formatos CSV soportados (MVP)

### `type=drivers`

```csv
external_id,name,email,phone
```

### `type=invoices`

```csv
external_invoice_id,invoice_number,driver_external_id,driver_name,service_date,branch_code,historical_sequence,historical_latitude,historical_longitude
```

## Configuración de ruteo

Variables relevantes en `.env`:

```dotenv
ROUTING_PROVIDER=auto
HERE_API_KEY=
ROUTING_CACHE_TTL_SECONDS=86400
ROUTING_FALLBACK_DEPOT_NAME="CEDIS Fallback"
ROUTING_FALLBACK_DEPOT_LAT=
ROUTING_FALLBACK_DEPOT_LNG=
```

- `auto`: usa HERE si hay API key; si no, usa mock.
- `here`: fuerza HERE y, si falla, cae a mock.
- `mock`: une puntos y calcula ETA aproximada.

## Pruebas y validación

```bash
php artisan test
```

Con Sail:

```bash
./vendor/bin/sail artisan test
```

Casos clave cubiertos:
- `tests/Feature/Ingestion/CsvImportTest.php`
- `tests/Feature/Simulation/SimulationPreviewTest.php`
- `tests/Feature/HealthCheckTest.php`

## Documentación adicional

- Setup detallado: `docs/dev-setup.md`
- Arquitectura técnica: `docs/architecture.md`
- Guía para agentes de código: `AGENTS.md`
