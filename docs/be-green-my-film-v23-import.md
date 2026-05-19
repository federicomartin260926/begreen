# Be Green My Film v23 Import

Esta documentación describe la fase técnica de preparación para importar `PLANTILLA_PS_v23.xlsx` como fuente actual del Plan de Sostenibilidad.

## Objetivo

- Validar la estructura real de la plantilla v23.
- Preparar la taxonomía del protocolo Be Green My Film.
- Ejecutar un `dry-run` sin escribir medidas en base de datos.
- Ejecutar `--apply` de forma controlada e idempotente cuando se quiera poblar catálogo y medidas.

## Comando

```bash
php bin/console app:import:be-green-my-film-v23 /ruta/a/PLANTILLA_PS_v23.xlsx --dry-run
```

Opciones:

- `--dry-run`: modo lectura y validación.
- `--report=/ruta/reporte.json`: guarda el reporte en JSON.
- `--apply`: escribe en base de datos solo si el reporte no contiene errores críticos.

## Idempotencia

La importación real resuelve:

- `Protocol` por `code = be-green-my-film`
- catálogos por `code`
- `Measure` por `protocol + importVersion = v23 + sourceRow`

Además, calcula `importHash` por fila para dejar trazabilidad de cambios y sincronizar relaciones sin duplicados.

Las áreas de impacto se crean desde el encabezado completo de la plantilla, así que `Cambio Uso Suelo` se mantiene como catálogo aunque no aparezca marcado en ninguna medida v23.

## Validaciones que realiza

- Hoja esperada: `Plan de Sostenibilidad`.
- Rango esperado: `A1:BM255`.
- 200 medidas exactas.
- 565 puntos totales.
- Distribución exacta de puntuaciones:
  - 5 puntos: 28 medidas
  - 4 puntos: 22 medidas
  - 3 puntos: 50 medidas
  - 2 puntos: 87 medidas
  - 1 punto: 13 medidas
- 3 fuentes de verificación por medida.
- Prioridades de verificación: 1, 2 y 3.
- Al menos 1 ODS por medida.
- Al menos 1 área de impacto por medida.
- Al menos 1 eje de triple balance por medida.

## Avisos conocidos

- La fila 184 no marca departamento y se trata como warning.
- `Cambio Uso Suelo` aparece en el encabezado pero no se usa en la plantilla v23 actual; se conserva como catálogo por compatibilidad con la plantilla.
- `HE` está presente como abreviatura de departamento y queda pendiente de confirmación funcional.

## Estado de la importación real

La importación real ya está disponible con `--apply`, pero debe usarse solo sobre una base sincronizada con el esquema de la Fase 1A.1.

## Edición manual del catálogo

El catálogo v23 importado ya puede ajustarse desde el admin de medidas.

Campos editables principales:

- nombre y nombre de revisión;
- descripción e implementación;
- protocolo, categoría y bloque;
- departamentos múltiples;
- ODS múltiples;
- áreas de impacto;
- triple balance;
- fuentes de verificación con prioridad 1, 2 y 3;
- puntuación y obligatoriedad.

Validaciones principales:

- el catálogo canónico v23 espera 200 medidas y 565 puntos;
- cada medida v23 debe tener bloque, al menos un departamento, al menos un ODS, al menos un área de impacto, al menos un eje de triple balance y fuentes de verificación;
- la puntuación v23 debe estar entre 1 y 5.

El importador sigue siendo la fuente inicial de datos, pero el admin ya permite ajustar el catálogo sin rehacer la importación. No hay versionado avanzado en esta fase.

En el flujo del plan, `be-green-my-film` usa ahora exclusivamente el catálogo v23. Las dos medidas legacy del mismo protocolo quedan fuera del recorrido canónico y no deben aparecer en vistas, PDF ni contadores.

Compatibilidad legacy:

- se mantienen los campos antiguos de `Measure` porque todavía los consume parte del backend;
- `department` singular y `ods` singular se rellenan con el primer valor detectado;
- `verificationSources` se sigue llenando como texto resumido para no romper vistas antiguas;
- `EsG`, `Scope` y `CategoryGhg` no forman parte de esta fase.
- `HE` se normaliza funcionalmente como `HE / Home Economist`.

## Nota histórica

- `PLANTILLA_PS_v18.xlsx` queda como referencia histórica.
- La fuente de trabajo actual es exclusivamente `PLANTILLA_PS_v23.xlsx`.
