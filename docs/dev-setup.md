# Desarrollo local

Este documento describe como levantar, validar y probar el proyecto en local.

## Prerrequisitos

- `PHP 8.2+`
- `Composer`
- `Node.js 20+` y `npm`
- recomendado: `Docker` + `Docker Compose` para usar Sail

## Opcion A: Sail

### 1. Preparar entorno

```bash
cp .env.example .env
composer install
```

Ajusta `.env` para Sail:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=ruteo
DB_USERNAME=sail
DB_PASSWORD=password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
```

Si quieres routing vial real:

```dotenv
ROUTING_PROVIDER=auto
HERE_API_KEY=tu_api_key
```

### 2. Levantar contenedores

```bash
./vendor/bin/sail up -d
./vendor/bin/sail ps
```

### 3. Inicializar aplicacion

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
```

### 4. Elegir modo frontend

Modo de desarrollo frontend:

```bash
./vendor/bin/sail npm run dev
```

Modo rapido para navegar y probar desde Windows:

```bash
./vendor/bin/sail npm run build
rm -f public/hot
```

Usa el segundo modo cuando no estes tocando Vue o CSS. En Windows + WSL suele ser bastante mas rapido.

## Opcion B: local sin Docker

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --seed
composer run dev
```

## Datos de demo

Para probar el flujo completo de demo:

```bash
./vendor/bin/sail php scripts/prepare_import_files.php
./vendor/bin/sail artisan demo:load-generated-data
```

Eso deja:
- `depots`
- `branches`
- `drivers`
- `invoices`
- `route_batches`
- `invoice_stops`
- asignaciones `driver -> depot`

Usuario seed de desarrollo:
- email: `test@example.com`
- password: `password`

## Validacion automatica

Suite completa:

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail npm run build
```

Tests por flujo:

```bash
./vendor/bin/sail artisan test --filter=CsvImportTest
./vendor/bin/sail artisan test --filter=SimulationPreviewTest
./vendor/bin/sail artisan test --filter=JourneyComparisonTest
./vendor/bin/sail artisan test --filter=PlanningScenarioTest
./vendor/bin/sail artisan test --filter=HealthCheckTest
```

Nota operativa:
- no ejecutes varios comandos independientes de `artisan test` en paralelo contra la misma base `testing`
- si necesitas paralelismo, usa `artisan test --parallel`

## Validacion manual minima

### A. Comparador historico

1. Navega a `/dashboard/simulate`
2. Filtra por fecha o conductor
3. Selecciona una jornada
4. Verifica:
   - metricas historico vs sugerido
   - delta
   - provider activo
   - no comparables
   - excluidas
   - mapa con popups y seleccion sincronizada

### B. Planillado diario

1. Navega a `/dashboard/planning-scenarios`
2. Elige fecha y depot
3. Crea o refresca el escenario
4. Entra al detalle
5. Usa `Generar propuesta base`
6. Verifica:
   - resumen del escenario
   - conductores activos del depot
   - jornadas propuestas
   - paradas no asignadas
   - paradas excluidas
   - mapa de la propuesta con cambio de conductor

## Inspeccion de datos

Herramienta recomendada:
- `DBeaver` conectado a PostgreSQL local

Conexion local con Sail:
- Host: `localhost`
- Port: `5432`
- Database: `ruteo`
- Username: `sail`
- Password: `password`

Tablas clave para revisar:
- `drivers`
- `depots`
- `branches`
- `invoices`
- `route_batches`
- `invoice_stops`
- `planning_scenarios`
- `planning_scenario_stops`
- `planning_scenario_journeys`

## Playwright y validacion UX

Playwright sirve bien para validar formularios, listas y payloads, pero en este entorno Firefox headless bajo WSL no siempre logra crear contexto `WebGL`.

Consecuencia:
- sirve para validar flujo y datos
- no siempre sirve para confirmar visualmente el mapa

Para revisar el mapa, prioriza:
- navegador real en Windows
- `http://localhost`

## Problemas conocidos

### La app carga lenta desde Windows

Usa:

```bash
./vendor/bin/sail npm run build
rm -f public/hot
```

Normalmente mejora bastante frente a Vite HMR.

### El mapa sale con linea recta

Revisa:
- `ROUTING_PROVIDER`
- `HERE_API_KEY`

Si el provider activo es `mock`, la ruta es una aproximacion y la UI lo indica.
