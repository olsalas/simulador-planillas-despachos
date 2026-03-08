# Guia de prueba para usuario operativo

Esta guia esta pensada para planilladores, supervisores o usuarios de operacion que quieren empezar a probar el MVP sin entrar al codigo.

## Que puede probar hoy

Hoy el sistema permite probar dos cosas distintas:

1. `Comparador historico`
   Permite ver como fue una jornada historica y compararla contra una mejor secuencia posible.
2. `Planillado diario`
   Permite crear un escenario por fecha y depot, generar una propuesta base y revisar como quedarian las jornadas propuestas.

## Antes de empezar

Necesitas:
- acceso al sistema
- un usuario valido
- datos cargados para la fecha que quieres revisar

En ambiente local de demo:
- URL: `http://localhost`
- usuario: `test@example.com`
- clave: `password`

## Flujo 1: comparador historico

Pantalla:
- `Simular`
- URL: `/dashboard/simulate`

### Objetivo

Responder:
- `como fue`
- `como pudo ser`

### Pasos

1. Abre `Simular`
2. Filtra por fecha o por conductor
3. Selecciona una jornada del listado
4. Revisa el panel de metricas
5. Cambia entre:
   - `Como fue`
   - `Como pudo ser`
6. Haz clic en los puntos del mapa
7. Revisa las listas laterales

### Que observar

- si la secuencia historica tiene sentido
- si la sugerida reduce distancia o tiempo
- si hay paradas `no comparables`
- si hay paradas `excluidas`
- si el punto de salida parece correcto

### Como interpretar el mapa

- `D` representa el depot o CEDIS
- los marcadores numerados representan la secuencia
- un clic en el mapa abre informacion del punto
- un clic en la lista resalta el punto correspondiente

### Atencion con el provider

Si la pantalla muestra `mock`:
- la linea del mapa es aproximada
- sirve para revisar orden y logica
- no representa el recorrido vial exacto

Si la pantalla muestra `here`:
- la ruta usa geometria vial real

## Flujo 2: propuesta diaria de planillado

Pantallas:
- `Planificar`
- URL: `/dashboard/planning-scenarios`

### Objetivo

Responder:
- dadas las facturas del dia para un depot, como podria organizarse una propuesta base de planillado

### Pasos

1. Abre `Planificar`
2. Elige:
   - fecha
   - depot / CEDIS
3. Haz clic en `Crear o actualizar escenario`
4. Entra al detalle del escenario
5. Haz clic en `Generar propuesta base`
6. Revisa:
   - resumen del escenario
   - conductores activos del depot
   - jornadas propuestas
   - paradas no asignadas
   - excluidas
   - mapa de la propuesta

### Como leer el detalle

#### Resumen del escenario

Te dice:
- cuantas facturas candidatas entraron
- cuantas paradas elegibles se detectaron
- cuantas quedaron excluidas
- cuantos conductores activos tiene el depot

#### Jornadas propuestas

Cada jornada propuesta muestra:
- conductor
- cantidad de paradas
- cantidad de facturas
- distancia estimada
- duracion estimada
- lista secuenciada de puntos

#### No asignadas

Son paradas operables que la heuristica actual no logro acomodar en la propuesta base.

#### Excluidas

Son puntos que no entran al algoritmo por falta de datos confiables, por ejemplo:
- sucursal faltante
- sucursal sin geocodigo

### Como usar el mapa de la propuesta

1. En la seccion `Mapa de la propuesta`, elige una jornada
2. Revisa la ruta y sus puntos
3. Cambia de conductor para revisar otra jornada
4. Haz clic en puntos para ver detalle

### Que feedback es mas util

Reporta si ves:
- conductor saliendo desde un depot incorrecto
- secuencias que claramente no tienen sentido operativo
- puntos que deberian agruparse de otra forma
- demasiadas paradas no asignadas
- exclusiones que en realidad deberian ser operables
- diferencias fuertes entre historico y propuesta que merecen analisis

## Limitaciones actuales del MVP

1. La propuesta diaria actual es una heuristica base, no un optimizador logistico final.
2. La calidad del resultado depende de:
   - sucursales bien geocodificadas
   - conductores asociados a un depot correcto
   - datos historicos consistentes
3. El sistema aun no administra todas las reglas de negocio desde una UI completa.
4. Las zonas todavia no participan de forma fuerte en la propuesta actual.

## Checklist de prueba rapida

### Comparador historico

- pude encontrar la jornada correcta
- entendi `Como fue`
- entendi `Como pudo ser`
- vi un delta util
- identifique excluidas o no comparables

### Planillado diario

- pude crear el escenario
- entendi el resumen del snapshot
- pude generar propuesta base
- pude cambiar de conductor en el mapa
- pude identificar no asignadas y excluidas

## Donde reportar feedback

Cuando reportes una observacion, incluye:
- fecha
- depot
- conductor si aplica
- pantalla donde lo viste
- que esperabas ver
- captura si el mapa o la secuencia se ven raros
