# Commercial Plan Features

Este documento resume qué implican hoy las funciones principales que aparecen en la ficha de edición de planes comerciales.

La regla general actual es:

- `ProjectFeatureGate` y `CommercialPlanResolver` son la fuente efectiva de acceso.
- La ficha de edición de planes solo cambia la configuración guardada en `CommercialPlan`.
- Algunas opciones ya tienen efecto real en runtime.
- Otras están sembradas en la configuración, pero todavía no tienen una aplicación funcional clara.

## Funciones con efecto real hoy

### PDF por departamentos

Claves relacionadas:

- `sustainability_plan.department_pdf`
- `sustainability_plan.export.department_pdf`
- `sustainability_plan.watermark_free_pdf`
- `sustainability_plan.history`

Efecto actual:

- habilita el PDF por departamentos;
- habilita el export asociado;
- en la configuración comercial también activa la posibilidad de PDF sin watermark y el historial relacionado.

Estado:

- sí tiene efecto real.

### Exportaciones avanzadas

Claves relacionadas:

- `sustainability_plan.advanced_exports`
- `sustainability_plan.export.category`
- `sustainability_plan.export.department`
- `sustainability_plan.export.impact_area`
- `sustainability_plan.export.triple_balance`
- `sustainability_plan.export.ods`
- `sustainability_plan.export.excel`

Efecto actual:

- habilita exportaciones avanzadas en el plan;
- afecta a los exports agrupados y a Excel.

Estado:

- sí tiene efecto real.

### Comentarios personalizados

Clave canónica:

- `sustainability_plan.public_comments`

Alias compatible:

- `sustainability_plan.custom_comments`

Efecto actual:

- desbloquea el campo de comentarios públicos/observaciones en la revisión del plan;
- forma parte de los bloqueos comerciales en `PlanController` para campos Pro-only;
- se guarda como `public_comments` en la ficha comercial, con `custom_comments` solo como alias de lectura.

Estado:

- sí tiene efecto real.

### Notas internas

Clave:

- `sustainability_plan.internal_notes`

Efecto actual:

- permite editar `internalNotes` en las medidas del plan;
- si la feature no está disponible, la escritura queda bloqueada.

Estado:

- sí tiene efecto real.

### Responsables

Clave:

- `sustainability_plan.responsibles`

Efecto actual:

- permite editar responsables por medida;
- también aparece en exportaciones, PDF y resúmenes de colaboración.

Estado:

- sí tiene efecto real.

### Medidas custom

Clave:

- `sustainability_plan.custom_measures`

Efecto actual:

- permite guardar el texto de medidas custom del plan;
- se muestra en la revisión y en el PDF cuando existe contenido.

Estado:

- sí tiene efecto real.

## Funciones con aplicación parcial o todavía no clara

### Checklist

Clave:

- `sustainability_plan.checklist`

Efecto actual:

- está disponible en la configuración comercial;
- no he encontrado todavía una aplicación funcional clara en controllers, servicios o vistas.

Estado:

- sembrada, sin aplicación real confirmada.

### Resumen de validación

Clave:

- `sustainability_plan.validation_summary`

Efecto actual:

- está disponible en la configuración comercial;
- no he encontrado todavía una aplicación funcional real que cambie comportamiento o acceso.

Estado:

- sembrada, sin aplicación real confirmada.

### Branding

Clave:

- `sustainability_plan.branding`

Efecto actual:

- está disponible en la configuración comercial;
- no he encontrado todavía una aplicación funcional real en el flujo actual.

Estado:

- sembrada, sin aplicación real confirmada.

## Observaciones sobre la UI comercial

- La ficha de edición de planes mezcla features ya operativas con otras que todavía son declarativas.
- `Comentarios personalizados` usa `public_comments` como clave real; `custom_comments` queda como alias técnico para no romper compatibilidad.
- `PDF por departamentos` y `Exportaciones avanzadas` no son simples etiquetas: ya están conectadas a exportación real.

## Relación con tiers

- `basic`: mantiene el flujo base y el watermark.
- `standard`: añade PDF por departamentos.
- `pro`: añade exportaciones avanzadas y las features colaborativas/Pro.

Para el detalle comercial general de tiers y reglas de medidas, ver [Commercial Tiers](commercial-tiers.md).
