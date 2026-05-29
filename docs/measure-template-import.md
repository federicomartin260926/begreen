# Measure Template Import

Esta documentación describe la importación de la plantilla estándar de medidas usada por el Plan de Sostenibilidad.

## Objetivo

- Validar la estructura real de la plantilla estándar.
- Preparar la taxonomía del protocolo correspondiente.
- Ejecutar un `dry-run` sin escribir medidas en base de datos.
- Ejecutar `--apply` de forma controlada e idempotente cuando se quiera poblar catálogo y medidas.

## Comando

```bash
php bin/console <internal-import-command> /ruta/a/plantilla_medidas.xlsx --dry-run
```

Nota: el nombre técnico heredado del comando y del archivo de trabajo puede conservar sufijos antiguos; funcionalmente la plantilla se trata como estándar.

Opciones:

- `--dry-run`: modo lectura y validación.
- `--report=/ruta/reporte.json`: guarda el reporte en JSON.
- `--apply`: escribe en base de datos solo si el reporte no contiene errores críticos.

## Idempotencia

La importación real resuelve:

- `Protocol` por `code` o `name`
- catálogos por `code`
- `Measure` por `protocol + sourceRow`

Además, calcula `importHash` por fila para dejar trazabilidad de cambios y sincronizar relaciones sin duplicados.

Las áreas de impacto se crean desde el encabezado completo de la plantilla, así que `Cambio Uso Suelo` se mantiene como catálogo aunque no aparezca marcado en ninguna medida.

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
- `Cambio Uso Suelo` aparece en el encabezado pero no se usa en la plantilla actual; se conserva como catálogo por compatibilidad con la plantilla.
- `HE` está presente como abreviatura de departamento y queda pendiente de confirmación funcional.

## Estado de la importación real

La importación real ya está disponible con `--apply`, pero debe usarse solo sobre una base sincronizada con el esquema de la Fase 1A.1.

## Edición manual del catálogo

El catálogo importado ya puede ajustarse desde el admin de medidas.

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

- el catálogo esperado contiene 200 medidas y 565 puntos;
- cada medida debe tener bloque, al menos un departamento, al menos un ODS, al menos un área de impacto, al menos un eje de triple balance y fuentes de verificación;
- la puntuación debe estar entre 1 y 5.

Formato de la columna `Bloque`:

- la plantilla acepta `code - name`, por ejemplo `inventario-y-planificacion - Inventario y planificación`;
- si solo se informa el texto visible, el importador genera un `code` determinista a partir del nombre;
- el bloque siempre se resuelve dentro del `protocol` de la fila;
- si la celda `Bloque` está vacía, `measureBlock` queda en `null`;
- la plantilla de medidas no define preguntas previas ni jerarquía de bloques;
- las preguntas previas se administran desde la tabla auxiliar de bloques o mediante fixtures/seed;
- los bloques se reutilizan por `protocol + code` y no se mezclan entre protocolos.

El importador sigue siendo la fuente inicial de datos, pero el admin ya permite ajustar el catálogo sin rehacer la importación. No hay versionado avanzado en esta fase.

En el flujo del plan, el protocolo canónico usado en esta fase mantiene solo sus medidas activas definidas para el plan; las medidas legacy del mismo protocolo quedan fuera del recorrido canónico y no deben aparecer en vistas, PDF ni contadores.

## Bloques de medidas

Un bloque de medidas es una subclasificación opcional dentro de un protocolo. Sirve para agrupar visualmente medidas dentro de una categoría principal y, en casos concretos, plantear una pregunta previa que permite omitir un conjunto de medidas no aplicables al proyecto.

Puntos clave:

- el bloque pertenece a un protocolo;
- no hay jerarquía de subbloques;
- la pregunta previa es opcional;
- si el usuario responde "No", el plan guarda la respuesta del bloque, marca automáticamente las medidas visibles del bloque como `No aplica` y excluye esas medidas de la puntuación máxima aplicable;
- si el usuario responde "Sí", las medidas marcadas automáticamente por el salto de bloque vuelven a estado pendiente para poder responderse de forma normal;
- el estado de origen se conserva para poder auditar o revertir el salto sin confundirlo con un `No aplica` manual;
- si un protocolo no tiene bloques, el flujo sigue funcionando y `measureBlock` puede quedar `null`.

Compatibilidad legacy:

- se mantienen los campos antiguos de `Measure` porque todavía los consume parte del backend;
- `department` singular y `ods` singular se rellenan con el primer valor detectado;
- `verificationSources` se sigue llenando como texto resumido para no romper vistas antiguas;
- `EsG`, `Scope` y `CategoryGhg` no forman parte de esta fase.
- `HE` se normaliza funcionalmente como `HE / Home Economist`.

## Nota histórica

- `PLANTILLA_PS_v18.xlsx` queda como referencia histórica.
- La fuente de trabajo actual es exclusivamente la plantilla estándar de medidas.
