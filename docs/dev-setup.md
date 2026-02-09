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
