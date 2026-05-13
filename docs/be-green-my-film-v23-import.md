# Be Green My Film v23 Import

Esta documentación describe la fase técnica de preparación para importar `PLANTILLA_PS_v23.xlsx` como fuente actual del Plan de Sostenibilidad.

## Objetivo

- Validar la estructura real de la plantilla v23.
- Preparar la taxonomía del protocolo Be Green My Film.
- Ejecutar un `dry-run` sin escribir medidas en base de datos.

## Comando

```bash
php bin/console app:import:be-green-my-film-v23 /ruta/a/PLANTILLA_PS_v23.xlsx --dry-run
```

Opciones:

- `--dry-run`: modo lectura y validación.
- `--report=/ruta/reporte.json`: guarda el reporte en JSON.
- `--apply`: reservado para la importación real, no implementada todavía.

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
- `Cambio Uso Suelo` aparece en el encabezado pero no se usa en la plantilla v23 actual.
- `HE` está presente como abreviatura de departamento y queda pendiente de confirmación funcional.

## Estado de la importación real

La importación real sigue pendiente. En esta fase solo existe el parser de lectura y validación para evitar cargar datos incorrectos en la base.

## Nota histórica

- `PLANTILLA_PS_v18.xlsx` queda como referencia histórica.
- La fuente de trabajo actual es exclusivamente `PLANTILLA_PS_v23.xlsx`.
