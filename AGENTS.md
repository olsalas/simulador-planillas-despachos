# AGENTS.md

Guía operativa para agentes de código que trabajen en este repositorio.

## Objetivo del proyecto

Aplicación Laravel + Inertia/Vue para:
1. Ingerir CSV de conductores/facturas.
2. Consolidar lotes por conductor+fecha.
3. Simular rutas y visualizarlas en mapa.

## Mapa rápido del código

- Rutas HTTP: `routes/web.php`
- Ingesta CSV: `app/Http/Controllers/Ingestion/UploadCsvController.php`, `app/Domain/Ingestion/CsvIngestionService.php`
- Batches y detalle: `app/Http/Controllers/Ingestion/BatchController.php`
- Simulación: `app/Http/Controllers/SimulationController.php`, `app/Domain/Simulation/BuildSimulationRouteService.php`
- Providers de ruteo: `app/Contracts/RoutingProvider.php`, `app/Domain/Routing/Providers/*.php`
- Resolución de provider: `app/Providers/AppServiceProvider.php`, `config/routing.php`, `config/services.php`
- Frontend principal:
  - Carga CSV: `resources/js/Pages/Ingestion/UploadCsv.vue`
  - Batches: `resources/js/Pages/Ingestion/Batches.vue`
  - Simulación: `resources/js/Pages/Simulation/Run.vue`

## Reglas de negocio que no se deben romper

1. `route_batches` es único por `driver_id + service_date`.
2. `invoice_stops` se reconstruye en cada consolidación por `driver + fecha`.
3. Facturas con sucursal faltante o sin geocódigo quedan en `pending` con `outlier_reason`.
4. Simulación excluye paradas sin geocódigo y debe reportarlas en `excluded_stops`.
5. El provider efectivo respeta fallback:
   - `auto`: HERE con key, si no mock.
   - `here`: si no hay key o falla request, cae a mock.
6. Cache de rutas depende de provider + waypoints ordenados + `return_to_depot`.

## Comandos de trabajo recomendados

### Entorno con Sail

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm run dev
./vendor/bin/sail artisan test
```

### Entorno local sin Docker

```bash
php artisan migrate
npm run dev
php artisan test
```

## Validación mínima por tipo de cambio

- Cambios en ingesta: `php artisan test --filter=CsvImportTest`
- Cambios en simulación/ruteo: `php artisan test --filter=SimulationPreviewTest`
- Cambios transversales/rutas: `php artisan test --filter=HealthCheckTest`
- Cambios de frontend Inertia/Vue: `npm run build`

## Convenciones prácticas para agentes

1. Mantener cambios acotados por flujo (ingesta, consolidación, simulación).
2. Evitar crear nuevas capas si la lógica ya vive en un servicio de dominio existente.
3. Preservar nombres/shape de payloads usados por Vue (`routePreview`, `importReport`, `flash`).
4. Al modificar contratos del backend, actualizar de inmediato la página Vue que consume esa respuesta.
5. Si se agrega un provider de ruteo, registrar binding en `AppServiceProvider` y variables en `.env.example`.

## Riesgos conocidos

- `README.md` y docs deben mantenerse alineados con comandos reales de Sail/local.
- Clases `*Domain.php` vacías son placeholders; no asumir que contienen lógica activa.
- En tests se usa configuración de cache/cola en memoria para velocidad; no extrapolar tiempos reales de Redis.
