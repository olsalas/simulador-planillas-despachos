# Desarrollo local (Sail)

## 1) Levantar contenedores

```bash
./vendor/bin/sail up -d
```

Verificar estado:

```bash
./vendor/bin/sail ps
```

## 2) Instalar dependencias (si aplica)

```bash
./vendor/bin/sail composer install
./vendor/bin/sail npm install
```

## 3) Ejecutar migraciones

```bash
./vendor/bin/sail artisan migrate
```

Recrear desde cero (solo en local):

```bash
./vendor/bin/sail artisan migrate:fresh
```

## 4) Levantar frontend con Vite

```bash
./vendor/bin/sail npm run dev
```

Build de assets:

```bash
./vendor/bin/sail npm run build
```

## 5) Ejecutar tests

Suite completa:

```bash
./vendor/bin/sail artisan test
```

Test puntual:

```bash
./vendor/bin/sail artisan test --filter=HealthCheckTest
```

## 6) Formatos CSV soportados (MVP)

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

## 7) Simulación con mapa (PR #3)

Variables opcionales en `.env`:

```dotenv
ROUTING_PROVIDER=auto
HERE_API_KEY=
ROUTING_CACHE_TTL_SECONDS=86400
```

- `ROUTING_PROVIDER=auto`: usa HERE si hay API key; si no, usa mock.
- `ROUTING_PROVIDER=here`: fuerza HERE (si falla o no hay key, cae a mock).
- `ROUTING_PROVIDER=mock`: siempre une puntos (solo UI/demo).

Validación manual mínima:

1. Crea/asegura un `depot` con lat/lng y un `route_batch` con `invoice_stops` geocodificados.
2. Navega a `/dashboard/simulate`.
3. Selecciona batch y ejecuta `Generar ruta`.
4. Verifica en el mapa:
   - Marker `D` (depot)
   - Markers numerados de paradas
   - Polilínea de ruta
5. Repite la misma simulación y confirma `cache: hit` en panel.
6. Para validar ruta de calle real (no líneas rectas), define `HERE_API_KEY` y usa `ROUTING_PROVIDER=here` o `auto`.
