# Comparativas de planes comerciales

Las comparativas de Elaboración e Implementación se preparan en `CommercialPlanComparisonBuilder`. Twig recibe una estructura normalizada y no decide prestaciones, límites ni precios.

## Datos de cada plan

Para Basic, Standard y Pro se leen de `CommercialPlan` el nombre, descripción, código, precio base, moneda, orden, estado activo, límite de evidencias, marca de agua, puntuaciones permitidas y el mapa completo de features. No se exponen Stripe Price IDs.

El precio visible es:

- el importe de `StripeProjectCheckoutService::getAvailableUpgradeTargets()` cuando hay un upgrade disponible;
- en otro caso, `CommercialPlan.priceAmount`;
- para Standard → Pro, el importe específico del upgrade preparado por Stripe, no el precio completo de Pro.

Por tanto, los cambios de precio, moneda, orden, estado activo, límites, puntuaciones y features realizados en Planes comerciales llegan a la estructura sin modificar Twig. Los textos clasificados como estáticos siguen requiriendo cambiar traducciones y no representan configuración persistida.

## Matriz de fuentes

| Fase | Fila de comparativa | Fuente actual | Dinámica | Observaciones |
|---|---|---|---|---|
| Ambas | Cabecera del plan | `CommercialPlan.name`, `code`, `priceAmount`, `priceCurrency`, `sortOrder`, `active` | Sí | El importe de un upgrade prevalece sobre el precio base. Standard → Pro usa su importe específico. |
| Ambas | Plan actual y CTA | Suscripción por fase + targets de `StripeProjectCheckoutService` | Sí | Los planes inferiores permanecen visibles, pero solo los targets permitidos tienen checkout. |
| Elaboración | Nº de medidas | `allowed_scores` + catálogo del protocolo | Sí | Dato derivado: cuenta las medidas del protocolo cuyas puntuaciones están permitidas. |
| Elaboración | Marcar medida como crítica | Contenido comercial estático | No | La criticidad existe en Elaboración, pero no hay una feature o límite comercial que la controle por plan. |
| Elaboración | Observaciones por medida | Disponibilidad general | No | Está disponible para Basic, Standard y Pro; no depende de una feature comercial. |
| Elaboración | Descarga en PDF | Features `sustainability_plan.department_pdf` y `sustainability_plan.advanced_exports` | Sí | Dato derivado. El texto resume niveles de exportación configurados; no se asigna por tier. |
| Elaboración | Branding del PDF | `CommercialPlan.watermarkEnabled` + feature `sustainability_plan.branding` | Sí | Dato derivado. La redacción comercial simplifica dos capacidades distintas. |
| Elaboración | Niveles de compromiso | Contenido comercial estático | No | No existe feature comercial específica. |
| Elaboración | Proyectos simultáneos | Sin relación técnica actual | No | `CommercialPlan` no tiene un límite de proyectos simultáneos. |
| Elaboración | Añadir medida propia | Feature `sustainability_plan.custom_measures` | Sí | Cambia automáticamente desde administración. |
| Elaboración | Recuperar medida descartada | Sin relación técnica actual | No | El diseño distingue planes, pero el servicio actual de recuperación no está gobernado por una feature comercial. La fila puede diferir del comportamiento real hasta modelar esa capacidad. |
| Elaboración | Envío del PDF por email | Funcionalidad aún no desarrollada como feature comercial | No | Los valores son contenido comercial centralizado en traducciones. |
| Elaboración | Histórico por medida | Feature `sustainability_plan.history` | Sí | Cambia automáticamente desde administración. |
| Elaboración | Alertas de cambio de nivel | Funcionalidad aún no desarrollada como feature comercial | No | Email e in-app son contenido comercial estático. |
| Elaboración | Exportación a Excel | Feature `sustainability_plan.export.excel` | Sí | La comparativa indica disponibilidad real. Los textos detallados del diseño no se usan para inventar subniveles no configurados. |
| Elaboración | % de elección de medida en la plataforma | Funcionalidad aún no desarrollada | No | No hay dato agregado ni feature comercial actual. |
| Implementación | Marcar medida ejecutada (nº usuarios) | Sin relación técnica actual | No | No existe límite de usuarios ejecutores en `CommercialPlan`; 1/10/100 es contenido comercial del diseño. |
| Implementación | Subir archivos (evidencias) | `CommercialPlan.maxEvidenceCount` + feature `sustainability_plan.evidence_upload` | Sí | Muestra ausencia, límite numérico o ilimitadas según la configuración real. |
| Implementación | Vista de progreso | Contenido comercial estático | No | El progreso global existe, pero no hay variantes comerciales configurables. |
| Implementación | Niveles de compromiso | Contenido comercial estático | No | No existe feature comercial específica. |
| Implementación | Proyectos simultáneos | Sin relación técnica actual | No | `CommercialPlan` no tiene ese límite. |
| Implementación | Poner observaciones | Disponibilidad general | No | Está disponible para Basic, Standard y Pro; no depende de una feature comercial. |
| Implementación | Notas internas | Feature `sustainability_plan.internal_notes` | Sí | Cambia automáticamente desde administración. |
| Implementación | Responsables | Feature `sustainability_plan.responsibles` | Sí | Cambia automáticamente desde administración. |
| Implementación | Checklist | Feature `sustainability_plan.checklist` | Sí | Cambia automáticamente desde administración. |
| Implementación | PDF por departamentos | Feature `sustainability_plan.department_pdf` | Sí | Cambia automáticamente desde administración. |
| Implementación | Exportación total del proyecto | Feature `sustainability_plan.advanced_exports` | Sí | Resume el conjunto de exportaciones avanzadas; las subfeatures permanecen disponibles en el mapa del plan. |
| Implementación | Resumen de validación | Feature `sustainability_plan.validation_summary` | Sí | Cambia automáticamente desde administración. |
| Implementación | Branding | Feature `sustainability_plan.branding` | Sí | Cambia automáticamente desde administración. |
| Implementación | Alertas de cambio de nivel | Funcionalidad aún no desarrollada como feature comercial | No | Email e in-app son contenido comercial estático. |

## Diferencias respecto al diseño

Los importes 29 €, 49 € y 20 € de los diseños son ejemplos visuales y no están escritos en Twig ni en traducciones. El diseño también presupone prestaciones por tier; cuando existe una feature o límite persistido, prevalece siempre la configuración real aunque produzca una combinación distinta. Las filas estáticas se mantienen únicamente para conservar el contenido aprobado mientras no exista una relación técnica, y están centralizadas en traducciones con `source: static` en la estructura preparada.
