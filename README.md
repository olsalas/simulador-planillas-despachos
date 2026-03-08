# Ruteo

Aplicacion Laravel + Inertia/Vue para construir un asistente de planillado logistico en dos capas:

1. `Comparador historico`
   Permite ver `como fue` una jornada historica de conductor + dia y compararla contra `como pudo ser` con una secuencia sugerida mejor.
2. `Planillado diario`
   Permite crear un escenario por `fecha + depot`, generar una propuesta base de asignacion y visualizar las jornadas propuestas en mapa.

La ingesta CSV sigue siendo importante, pero ya no es el unico valor del producto. Hoy el repo ya soporta:
- carga de historicos (`drivers` e `invoices`),
- consolidacion operativa,
- comparacion historica vs sugerida,
- generacion de escenarios diarios por depot,
- propuesta base de asignacion a conductores,
- visualizacion de rutas en mapa con HERE o fallback `mock`.

## Stack tecnico

- Backend: `Laravel 12`, `PHP 8.2+`
- Frontend: `Inertia.js 2`, `Vue 3`, `Vite`, `Tailwind CSS`
- Base de datos local recomendada: `PostgreSQL`
- Cache local con Sail: `Redis`
- Mapa: `MapLibre GL` con tiles OSM
- Routing:
  - `HERE` si hay `HERE_API_KEY`
  - `mock` como fallback o modo demo

## Capacidades actuales

### 1. Ingesta historica

- Carga CSV de `drivers`
- Carga CSV de `invoices`
- Trazabilidad por `ingestion_batches` e `ingestion_rows`
- Reconstruccion de:
  - `route_batches`
  - `invoice_stops`

### 2. Comparador historico

Pantalla: `/dashboard/simulate`

Permite:
- filtrar jornadas historicas por fecha y conductor,
- comparar `Como fue` vs `Como pudo ser`,
- ver metricas y delta,
- ver paradas no comparables y excluidas,
- visualizar la ruta en mapa,
- abrir popups de puntos y sincronizar lista con mapa.

### 3. Escenarios de planillado diario

Pantallas:
- `/dashboard/planning-scenarios`
- `/dashboard/planning-scenarios/{id}`

Permite:
- crear o refrescar un snapshot por `fecha + depot`,
- persistir demanda candidata, excluidas y conductores del depot,
- generar una propuesta base de asignacion,
- crear jornadas propuestas por conductor,
- ver rutas sugeridas en mapa por jornada,
- revisar paradas candidatas, no asignadas y excluidas.

## Rutas principales de UI

- `/dashboard`
- `/dashboard/upload-csv`
- `/dashboard/batches`
- `/dashboard/batches/{routeBatch}`
- `/dashboard/simulate`
- `/dashboard/planning-scenarios`
- `/dashboard/planning-scenarios/{planningScenario}`

## Inicio rapido

### Opcion recomendada: Sail

```bash
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

Abrir:
- `http://localhost`

Usuario seed de desarrollo:
- email: `test@example.com`
- password: `password`

### Retomar una sesion de desarrollo

```bash
git checkout main
git pull origin main
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

Luego elige:

- si vas a tocar Vue/CSS: `./vendor/bin/sail npm run dev`
- si solo vas a navegar y probar desde Windows:

```bash
./vendor/bin/sail npm run build
rm -f public/hot
```

### Detener el entorno local

Si tienes `npm run dev` corriendo:
- detener con `Ctrl+C` en esa terminal

Luego:

```bash
./vendor/bin/sail down
```

### Opcion local sin Docker

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --seed
composer run dev
```

## Datos demo y carga reproducible

El repo incluye un flujo reproducible para preparar y cargar datos de demo desde los extracts del sistema origen.

Preparar archivos normalizados:

```bash
./vendor/bin/sail php scripts/prepare_import_files.php
```

Salida local:
- `docs/generated/depots_seed.csv`
- `docs/generated/driver_depot_assignment_ready.csv`
- `docs/generated/drivers_import.csv`
- `docs/generated/branches_seed.csv`
- `docs/generated/invoices_import.csv`

Cargar demo local:

```bash
./vendor/bin/sail artisan demo:load-generated-data
```

Notas:
- hace `upsert` de `branches` y `depots`
- aplica asignaciones `driver -> depot`
- importa `drivers` e `invoices` con el flujo real de ingesta
- carga facturas por chunks para evitar limites de locks en PostgreSQL

## CSV soportados hoy

### `type=drivers`

```csv
external_id,name,email,phone
```

### `type=invoices`

```csv
external_invoice_id,invoice_number,driver_external_id,driver_name,service_date,branch_code,historical_sequence,historical_latitude,historical_longitude
```

Plantillas:
- `docs/templates/depots_template.csv`
- `docs/templates/driver_depot_assignment_template.csv`
- `docs/templates/branches_template.csv`
- `docs/templates/drivers_template.csv`
- `docs/templates/invoices_template.csv`

Mapa de campos desde sistema origen:
- `docs/import-source-mapping.md`

## Configuracion de routing

Variables relevantes en `.env`:

```dotenv
ROUTING_PROVIDER=auto
HERE_API_KEY=
ROUTING_CACHE_TTL_SECONDS=86400
ROUTING_FALLBACK_DEPOT_NAME="CEDIS Fallback"
ROUTING_FALLBACK_DEPOT_LAT=
ROUTING_FALLBACK_DEPOT_LNG=
```

Comportamiento:
- `auto`: usa HERE si hay key; si no, `mock`
- `here`: intenta HERE y si falla, cae a `mock`
- `mock`: conecta puntos y devuelve una aproximacion

## Validacion minima

Suite completa:

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail npm run build
```

Tests utiles por flujo:

```bash
./vendor/bin/sail artisan test --filter=CsvImportTest
./vendor/bin/sail artisan test --filter=SimulationPreviewTest
./vendor/bin/sail artisan test --filter=JourneyComparisonTest
./vendor/bin/sail artisan test --filter=PlanningScenarioTest
./vendor/bin/sail artisan test --filter=HealthCheckTest
```

Nota operativa:
- no lances procesos independientes de `artisan test` en paralelo contra la misma base `testing`
- si necesitas paralelismo, usa `artisan test --parallel`

## Flujo recomendado para probar hoy

### A. Comparador historico

1. Entrar a `/dashboard/simulate`
2. Filtrar por fecha y conductor
3. Seleccionar una jornada
4. Comparar `Como fue` vs `Como pudo ser`
5. Revisar delta, no comparables, excluidas y mapa

### B. Planillado diario

1. Entrar a `/dashboard/planning-scenarios`
2. Elegir fecha y depot
3. Crear o refrescar escenario
4. Entrar al detalle
5. Generar propuesta base
6. Revisar jornadas propuestas, no asignadas, excluidas y mapa por conductor

## Documentacion adicional

- Setup y validacion local: `docs/dev-setup.md`
- Arquitectura actual: `docs/architecture.md`
- Guia para usuario operativo: `docs/operator-testing-guide.md`
- Guia para agentes: `AGENTS.md`
