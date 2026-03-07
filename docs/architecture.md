# Arquitectura

Resumen técnico de la arquitectura actual del MVP de ruteo.

## Vista general

La aplicación sigue una arquitectura monolítica en Laravel con frontend desacoplado por Inertia/Vue.

- Entrada web: controladores HTTP (`app/Http/Controllers/*`)
- Reglas de negocio: servicios de dominio (`app/Domain/*`)
- Persistencia: modelos Eloquent + migraciones
- Presentación: páginas Vue en `resources/js/Pages/*`

## Contextos funcionales

### 1) Ingesta

Responsable de aceptar CSV y convertirlo en datos normalizados.

Componentes clave:
- `UploadCsvController`
- `UploadCsvRequest`
- `CsvIngestionService`
- `IngestionBatch`, `IngestionRow`, `Invoice`, `RouteBatch`, `InvoiceStop`

Flujo:
1. Se crea `ingestion_batch` en estado `processing`.
2. Se recorren filas CSV y se validan por tipo (`drivers` o `invoices`).
3. Cada fila genera `ingestion_row` con `valid` o `invalid`.
4. Facturas válidas alimentan `invoices` y se marcan outliers.
5. Se consolidan lotes por `driver + service_date` en `route_batches` e `invoice_stops`.

### 2) Simulación de ruta

Responsable de construir preview de ruta y métricas para UI de mapa.

Componentes clave:
- `SimulationController`
- `PreviewRouteRequest`
- `BuildSimulationRouteService`
- `RoutingProvider` (contrato)
- `HereRoutingProvider`, `MockRoutingProvider`

Flujo:
1. UI envía `route_batch_id` y `return_to_depot`.
2. Servicio obtiene paradas consolidadas (`invoice_stops`) con sucursal.
3. Excluye paradas sin geocódigo y las reporta en `excluded_stops`.
4. Resuelve depot en orden:
   - Depot del conductor
   - Primer depot activo
   - Fallback configurado en `.env`
   - Primera parada válida
   - Coordenadas hardcodeadas
5. Ejecuta provider con cache por firma de request.
6. Devuelve geometría, legs, métricas y bounds.

### 3) Visualización

`resources/js/Pages/Simulation/Run.vue` renderiza:
- línea de ruta (`LineString`) en MapLibre,
- marcador `D` para CEDIS,
- marcadores numerados para paradas,
- indicadores de distancia, tiempo, provider y cache hit/miss.

## Modelo de datos (MVP)

Entidades principales:
- `drivers`: maestro de conductor (opcionalmente ligado a `depots`).
- `branches`: sucursales con geocódigo.
- `invoices`: facturas históricas por conductor y fecha.
- `route_batches`: consolidado diario por conductor.
- `invoice_stops`: consolidado por sucursal para cada `route_batch`.
- `ingestion_batches`/`ingestion_rows`: trazabilidad de importación.

Restricciones relevantes:
- `route_batches`: unique (`driver_id`, `service_date`).
- `invoice_stops`: unique (`driver_id`, `branch_id`, `service_date`).
- `drivers.external_id` y `branches.code`: únicos.

## API interna relevante

### `POST /dashboard/simulate/preview`

Request:

```json
{
  "route_batch_id": 123,
  "return_to_depot": true
}
```

Response (shape simplificado):

```json
{
  "route_batch_id": 123,
  "provider": "mock",
  "cache_hit": false,
  "depot": { "lat": 19.5, "lng": -99.2, "source": "driver_depot" },
  "stops": [{ "sequence": 1, "lat": 19.51, "lng": -99.19 }],
  "excluded_stops": [],
  "metrics": { "distance_meters": 12345.6, "duration_seconds": 1600 },
  "geometry": [{ "lat": 19.5, "lng": -99.2 }],
  "bounds": { "min_lat": 19.5, "max_lat": 19.52, "min_lng": -99.2, "max_lng": -99.18 }
}
```

## Configuración y runtime

Variables de ruteo en `.env`:
- `ROUTING_PROVIDER=auto|here|mock`
- `HERE_API_KEY`
- `ROUTING_CACHE_TTL_SECONDS`
- `ROUTING_FALLBACK_DEPOT_*`

Resolución de provider en `AppServiceProvider`:
- HERE solo si hay API key válida.
- En cualquier falla operacional, fallback a mock.

## Cobertura de pruebas actual

- `tests/Feature/Ingestion/CsvImportTest.php`
- `tests/Feature/Simulation/SimulationPreviewTest.php`
- `tests/Feature/HealthCheckTest.php`

## Decisiones técnicas actuales

1. Consolidación por conductor+fecha simplifica el modelo para MVP.
2. Simulación usa `planned_sequence` si existe; si no, orden por ID.
3. Fallback a mock evita bloquear UI ante errores de proveedor externo.
4. Cache de rutas reduce costo/latencia en reintentos sobre el mismo batch.
