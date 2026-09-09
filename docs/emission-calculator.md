# Calculadora CO₂

Documento técnico y funcional de la arquitectura moderna de la Calculadora CO₂ de Begreen.

## Alcance actual

La Calculadora dispone de siete categorías modernas:

1. Transporte
2. Energía
3. Agua
4. Alojamientos
5. Catering
6. Residuos
7. Materiales y Productos

`Viajes` no es una categoría independiente. Es un tipo funcional dentro de Transporte junto con desplazamientos locales y mercancías.

La arquitectura legacy basada en `EmissionActivity` ha sido retirada. `EmissionRecord` ya no mantiene una relación `activity`.

`EmissionRecord::getEffectiveCategory()` se conserva como alias moderno de la categoría explícita del registro.

## Modelo moderno

### EmissionFactor

Campos funcionales principales:

- `categoryKey`
- `functionalKey`
- `criteria`
- `temporalType`
- `year`
- `value`
- `unit`
- `source`
- `sourceDetail`
- `metadata`

### EmissionRecord

Un registro moderno contiene, entre otros:

- proyecto;
- fase;
- categoría explícita;
- `amount`, nullable;
- `emission`, nullable;
- `status`;
- `registeredAt`;
- `calculationDetails`, con el snapshot moderno;
- attachments.

No existe relación con `EmissionActivity`.

`NULL` y `0` tienen semánticas distintas:

- `emission = NULL`: caso no calculable con los datos o metodología disponibles;
- `emission = 0`: resultado válido explícitamente igual a cero.

## Resolución de factores

### ANNUAL

- `year` obligatorio.
- Año exacto; si no existe, último anterior permitido.
- Nunca se selecciona un factor futuro.
- `activityYear` conserva el año real de actividad.

### VERSIONED

- `year` puede ser `NULL`.
- `factorYear` puede ser `NULL`.
- `activityYear` conserva el año de actividad.

### COMPOSITE

Permite cálculos compuestos por más de un componente. `year` y `factorYear` pueden ser `NULL`.

### PROXY_LCA

Representa factores proxy LCA cuando no existe un factor directo adecuado. El proxy debe quedar identificado.

### RULE

Representa reglas explícitas de dominio y puede producir factor, cero o resultado no calculable según la regla.

### Fallbacks

Los fallbacks pertenecen al backend. Nunca deben:

- inventar un factor;
- seleccionar un `ANNUAL` futuro;
- convertir silenciosamente `NULL` en cero;
- ser decididos por JavaScript.

## Autoridad del backend

El frontend sirve para interacción, selección de datos, preview y ayudas de entrada.

Nunca es autoridad sobre:

- `amount` final persistido;
- `emission`;
- factor;
- `factorYear`;
- fuente;
- `functionalKey`.

El backend reconstruye la entrada funcional, resuelve el factor y calcula el resultado antes de persistir.

La edición y la duplicación reconstruyen desde input/snapshot moderno.

## Snapshots

| Categoría | Snapshot |
| --- | --- |
| Transporte | `transport-v20` |
| Energía | `energy-v1` |
| Agua | `water-v1` |
| Alojamientos | `accommodation-v1` |
| Catering | `catering-v1` |
| Residuos | `waste-v1` |
| Materiales y Productos | `material-v1` |

En la base reconstruida desde fixtures se han comprobado 738 registros y todos usan una de estas siete versiones. No hay snapshots desconocidos.

## Categorías

### Transporte

- desplazamientos locales;
- viajes;
- mercancías;
- `startDate` / `endDate`;
- `activityYear = startDate.year`;
- 1.193 factores;
- 259 `functionalKey`;
- DEFRA 2026;
- snapshot `transport-v20`;
- ORS solo obtiene distancia.

### Energía

Incluye electricidad, generadores, baterías y tecnología digital.

Puede existir `NULL` cuando no hay base metodológica suficiente.

- 1.036 factores;
- snapshot `energy-v1`.

### Agua

Incluye sanitarios, duchas, limpieza y otros usos contemplados.

- normalización a m³;
- proxy geográfico cuando corresponde;
- 12 factores;
- snapshot `water-v1`.

### Alojamientos

- hotel;
- hostal/pensión;
- apartamento/vivienda;
- factores versionados y proxies;
- 3.256 factores;
- snapshot `accommodation-v1`.

### Catering

Contempla actividades y menús, incluyendo preparados y consumidos.

Utiliza `VERSIONED`, `COMPOSITE` y `PROXY_LCA`.

- 9 factores;
- snapshot `catering-v1`.

### Residuos

Contempla España/exterior, tipos, subtipos y destinos/tratamientos.

Utiliza `ANNUAL`, `VERSIONED` y `RULE`.

Incluye proxy UK, casos `Desconocido` y `NON_WASTE_ROUTE_ZERO`.

- 551 factores;
- snapshot `waste-v1`.

### Materiales y Productos

- 14 familias;
- conversiones;
- `ANNUAL`, `VERSIONED` y `RULE`;
- casos `NULL` cuando no existe cálculo válido;
- 508 factores;
- snapshot `material-v1`.

## Inventario de factores

| Categoría | Factores |
| --- | ---: |
| Transporte | 1.193 |
| Energía | 1.036 |
| Agua | 12 |
| Alojamientos | 3.256 |
| Catering | 9 |
| Residuos | 551 |
| Materiales y Productos | 508 |
| **Total** | **6.565** |

Por `temporalType`:

| Tipo | Factores |
| --- | ---: |
| `ANNUAL` | 5.971 |
| `VERSIONED` | 381 |
| `RULE` | 211 |
| `COMPOSITE` | 1 |
| `PROXY_LCA` | 1 |
| **Total** | **6.565** |

## Fixtures

Durante el desarrollo actual, los fixtures son la fuente de verdad para factores, registros de ejemplo, snapshots y datos maestros necesarios para reproducir la Calculadora.

No se mantienen backfills legacy.

| Categoría | Registros |
| --- | ---: |
| Transporte | 54 |
| Energía | 108 |
| Agua | 90 |
| Alojamientos | 108 |
| Catering | 72 |
| Residuos | 180 |
| Materiales y Productos | 126 |
| **Total** | **738** |

Los 738 registros actuales tienen `calculationDetails` y snapshot moderno reconocido.

## Attachments

Los justificantes pertenecen a `EmissionRecord`.

Formatos admitidos:

- PDF;
- JPEG/JPG;
- PNG;
- WEBP.

Límites actuales:

- máximo 4 archivos por subida;
- máximo 4 MiB por archivo.

El almacenamiento organiza los ficheros por:

`{projectId}/{recordId}/{storedName}`

La validación comprueba MIME y extensión.

La duplicación reconstruye el registro moderno pero no duplica sus attachments.

## Listados, informes y PDF

No deben depender de `EmissionActivity`.

Reglas:

- `NULL`: mostrar `—` o equivalente;
- `0`: mostrar cero;
- descripciones desde snapshot moderno;
- agrupaciones/sumatorios toleran `amount` y `emission` nullable;
- informe PDF «Detalle completo de registros» compatible con las siete categorías modernas.

`getEffectiveCategory()` puede utilizarse como alias de la categoría moderna.

## Administración de factores

`Administración > Factores de emisión` administra exclusivamente `EmissionFactor`.

Incluye:

- listado;
- detalle;
- alta;
- edición;
- eliminación;
- paginación;
- filtros;
- validación JSON;
- regeneración automática de `functionalKey`;
- validaciones por `temporalType`/`year`;
- representación diferenciada de `NULL` y cero.

No existe Admin funcional de `EmissionActivity`.

## Reconstrucción y validación

Targets locales relevantes:

```text
make schema-dump
make schema-update
make fixtures
make db-dump-fixtures
make test
make assets-build
```

El cierre exige que `doctrine:schema:update --dump-sql` no muestre cambios pendientes.

No se crean migraciones Doctrine para este cierre.

### Resultado del cierre #49

Validación automática:

- `git diff --check`: OK;
- lint Twig: 111/111 archivos OK;
- lint YAML: OK;
- lint de contenedor/configuración: OK;
- build de assets: OK, únicamente con warnings preexistentes de Sass/Bootstrap y `caniuse-lite`;
- tests focales de las siete categorías, Admin, attachments e informes: 79 tests / 530 assertions OK;
- validación focal posterior del ajuste de proxy de Catering: 4 tests / 24 assertions OK;
- Doctrine mapping/schema: OK;
- `doctrine:schema:update --dump-sql`: sin cambios pendientes;
- reconstrucción limpia de base de datos y fixtures: OK;
- tabla legacy `emission_activity`: ausente;
- columna legacy `emission_record.activity_id`: ausente.

QA manual representativa:

- navegación con las siete categorías modernas y Viajes integrado en Transporte: OK;
- Transporte: Taxi/VTC, Metro con fallback temporal y A pie con emisión cero: OK;
- `NULL` no calculable mostrado como `—`: OK;
- `0` mostrado como cero: OK;
- proxy LCA de Catering visible con fuente y versión: OK;
- edición y reconstrucción desde snapshot moderno: OK;
- duplicación de registro moderno: OK;
- attachments: subida, persistencia, descarga, no duplicación y eliminación: OK;
- informe PDF «Detalle completo de registros»: OK;
- Admin `EmissionFactor`: catálogo, filtro `PROXY_LCA` y detalle completo: OK.

## Preparación de despliegue

El despliegue no forma parte del cierre local. Se ejecutará únicamente cuando se autorice.

### Local antes del push

Comprobar:

- working tree;
- tests focales;
- lint PHP;
- lint Twig;
- lint YAML;
- container/config;
- Doctrine mapping/schema;
- fixtures completos;
- assets si hubo cambios frontend;
- dump limpio;
- checksum.

### Dump limpio de fixtures preparado

Artefacto local generado tras reconstrucción limpia de schema + fixtures:

`backups/begreen_clean_fixtures_20260909_101347.sql`

- tamaño: `7.175.747 bytes`;
- SHA-256: `77f96aa144910b0d64cd61ab392dae7e1a0485093187bf6ee896598995b69e6c`;
- factores `EmissionFactor`: `6.565`;
- registros `EmissionRecord`: `738`;
- snapshots modernos desconocidos: `0`;
- dependencias funcionales legacy: `0`.

Este es el dump de referencia preparado para la futura carga conjunta cuando se autorice el despliegue.

### Producción

Referencias previstas del Makefile:

```text
make db-backup-prod
make deploy-prod-build
make db-import-prod DUMP=...
make schema-dump-prod
make ps-prod
```

Secuencia:

1. registrar HEAD/release desplegado;
2. backup DB producción;
3. conservar dump previo;
4. verificar dump limpio;
5. deploy de código;
6. importar dump cuando se autorice;
7. comprobar schema;
8. comprobar contenedores;
9. post-check mínimo.

### Rollback

Conservar antes del despliegue:

- HEAD/release anterior;
- backup DB previo;
- dump nuevo identificado.

Si hay que revertir:

1. volver al release/commit anterior mediante el mecanismo real del proyecto;
2. restaurar backup DB si se importó el dump nuevo;
3. comprobar schema, contenedores y funcionalidad básica.

## Fuera de alcance / deltas futuros

No forman parte de esta entrega:

- imputaciones;
- emisiones evitadas;
- importación CSV/Excel;
- OCR;
- documentos/revisión automática;
- review queue;
- sincronización automática con Base Maestra;
- nuevas metodologías;
- integración Transporte ↔ Energía;
- aclaración UX en Transporte para indicar explícitamente que modos no compatibles con ORS, como Metro, requieren distancia manual aunque utilicen «Origen + destino»;
- rediseño de la Calculadora;
- nuevas features del Admin;
- metadata global adicional;
- automatizaciones avanzadas.

No deben confundirse con bugs pendientes.

## Legacy retirado

No deben volver a introducirse funcionalmente:

- `EmissionActivity`;
- tabla `emission_activity`;
- `EmissionRecord.activity`;
- formulario genérico legacy;
- rutas legacy `new/edit`;
- templates legacy;
- Viajes como categoría independiente;
- factores autoritativos hardcodeados en JavaScript;
- cálculo autoritativo en frontend.

Términos modernos como `activityYear`, `activityType` o actividades propias de materiales/residuos no implican dependencia de `EmissionActivity`.
