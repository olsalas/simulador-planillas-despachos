# Desarrollo local

Este documento describe cómo levantar el proyecto en local con y sin Docker.

## Prerrequisitos

- `PHP 8.2+`
- `Composer`
- `Node.js 20+` y `npm`
- Opcional (recomendado): `Docker` + `Docker Compose` para usar Sail

## Opción A: Sail (recomendada)

### 1) Preparar entorno

```bash
cp .env.example .env
composer install
```

Ajusta la sección DB en `.env` para Sail:

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

### 2) Levantar contenedores

```bash
./vendor/bin/sail up -d
./vendor/bin/sail ps
```

### 3) Inicializar aplicación

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
```

### 4) Frontend (Vite)

```bash
./vendor/bin/sail npm run dev
```

Build de assets:

```bash
./vendor/bin/sail npm run build
```

## Opción B: local sin Docker

Esta opción usa SQLite por defecto (`.env.example` ya viene preparado para eso).

### 1) Instalar dependencias

```bash
cp .env.example .env
composer install
npm install
```

### 2) Inicializar aplicación

```bash
php artisan key:generate
php artisan migrate
```

### 3) Levantar entorno de desarrollo

```bash
composer run dev
```

## Tests

Suite completa:

```bash
php artisan test
```

Con Sail:

```bash
./vendor/bin/sail artisan test
```

Nota operativa:
- Evita correr varios comandos independientes de `artisan test` al mismo tiempo contra la misma base `testing` en PostgreSQL.
- El flujo validado del proyecto es secuencial.
- Si se quiere paralelizar, debe hacerse con `artisan test --parallel` o con bases aisladas por proceso.

Tests clave por flujo:

```bash
php artisan test --filter=CsvImportTest
php artisan test --filter=SimulationPreviewTest
php artisan test --filter=HealthCheckTest
```

## Formatos CSV soportados (MVP)

### Conductores (`type=drivers`)

Headers esperados:

```csv
external_id,name,email,phone
```

### Facturas (`type=invoices`)

Headers esperados:

```csv
external_invoice_id,invoice_number,driver_external_id,driver_name,service_date,branch_code,historical_sequence,historical_latitude,historical_longitude
```

## Simulación con mapa

Variables opcionales en `.env`:

```dotenv
ROUTING_PROVIDER=auto
HERE_API_KEY=
ROUTING_CACHE_TTL_SECONDS=86400
ROUTING_FALLBACK_DEPOT_NAME="CEDIS Fallback"
ROUTING_FALLBACK_DEPOT_LAT=
ROUTING_FALLBACK_DEPOT_LNG=
```

- `ROUTING_PROVIDER=auto`: usa HERE si hay API key; si no, usa mock.
- `ROUTING_PROVIDER=here`: fuerza HERE (si falla o no hay key, cae a mock).
- `ROUTING_PROVIDER=mock`: siempre une puntos (solo UI/demo).

## Validación manual mínima

1. Crea/asegura un `depot` con lat/lng y un `route_batch` con `invoice_stops` geocodificados.
2. Navega a `/dashboard/simulate`.
3. Selecciona batch y ejecuta `Generar ruta`.
4. Verifica en el mapa:
   - Marker `D` (depot)
   - Markers numerados de paradas
   - Polilínea de ruta
5. Repite la misma simulación y confirma `cache: hit` en panel.
6. Para validar ruta de calle real (no líneas rectas), define `HERE_API_KEY` y usa `ROUTING_PROVIDER=here` o `auto`.
