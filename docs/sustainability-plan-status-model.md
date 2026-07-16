# Modelo funcional de estados del Plan de Sostenibilidad

Este documento fija el modelo funcional objetivo del Plan de Sostenibilidad y de sus medidas.
No introduce estados nuevos en código; solo deja cerradas las reglas de negocio que el repositorio debe respetar.

## 1. Principios generales

- El plan se organiza en dos dimensiones operativas principales: elaboración e implementación.
- La verificación o auditoría es una dimensión distinta y no debe mezclarse con el estado operativo de la implementación.
- El estado global del plan no debe sustituir el estado de cada medida.
- Las incidencias no son un estado por sí mismas; son actividad asociada a la medida.
- `No aplica` y `Descartada` son conceptos distintos y deben mantenerse separados.
- `A implementar` es una vista filtrada, no un estado persistente.

## 2. Elaboración de una medida

Durante la elaboración, cada medida puede quedar en uno de estos resultados funcionales:

- Pendiente de definir.
- Definida y marcada para implementar.
- Definida y marcada como no aplicable.
- Definida y descartada.

Reglas funcionales:

- La decisión de aplicabilidad se toma en la elaboración.
- `No aplica` significa que la medida no entra en la ejecución del plan.
- `Descartada` significa que la medida se conoció y se rechazó como parte del análisis.
- Cuando una medida se bloquea o se omite en pasos posteriores por no ser aplicable, el sistema debe tratarla como `No aplica`, no como `Descartada`.
- Si una medida se reabre por cambios del plan, su decisión anterior de aplicabilidad no debe perderse salvo que la revisión lo exija.

## 3. Implementación de una medida

La implementación se calcula a partir del estado real de `PlanMeasure` y de su seguimiento operativo.

Estados funcionales que deben distinguirse:

- `No aplica`.
- `Descartada`.
- `Pendiente`.
- `En curso`.
- `Implementada`.

Reglas:

- `Pendiente` cubre la medida que debe ejecutarse pero todavía no ha empezado.
- `En curso` cubre la medida que ya tiene avance material o actividad registrada.
- `Implementada` cubre la medida completada para la fase operativa.
- `Descartada` no es equivalente a `No aplica`.
- Si una medida implementada reabre trabajo, debe volver a un estado operativo coherente sin perder el historial funcional necesario.

## 4. Filtros funcionales

Las vistas del plan pueden agrupar medidas por estos filtros:

- Todas.
- A implementar.
- Pendientes.
- En curso.
- Implementadas.
- Descartadas.
- No aplican.

Reglas:

- `A implementar` debe resolver las medidas que todavía requieren ejecución.
- Los filtros de incidencias o criticidad son transversales y no sustituyen estos estados.
- No deben crearse pestañas funcionales nuevas si el objetivo solo es filtrar medidas ya existentes.

## 5. Incidencias

Las incidencias no constituyen un estado autónomo de la medida.

Reglas:

- Una incidencia activa debe impulsar la lectura de la medida como `En curso` si hay trabajo real asociado.
- Una medida puede estar `Implementada` y seguir teniendo incidencias abiertas si el flujo operativo así lo registra.
- La incidencia debe usarse como señal de seguimiento, no como sustituto del estado de implementación.

## 6. Verificación y auditoría

La verificación es una dimensión aparte del ciclo operativo.

Reglas:

- La revisión, el checklist y la auditoría no deben confundirse con la implementación.
- El resultado de verificación debe poder coexistir con cualquier estado operativo de la medida.
- La revisión puede producir evidencias, observaciones o correcciones solicitadas sin alterar por sí sola la naturaleza de la medida.
- Si más adelante se añaden estados de verificación, deben vivir separados del flujo de implementación.

## 7. Estado global del plan

El plan completo puede mostrarse con un estado global sencillo, orientado a operación:

- Pendiente / Sin iniciar: se consideran equivalentes a nivel visual y no deben modelarse como dos estados distintos.
- En curso.
- Completado.
- Reabierto.
- Bloqueada: queda reservada para una definición futura y no debe implementarse hasta que Franc defina una regla funcional concreta sobre cuándo se activa y cómo se resuelve.

Reglas:

- Este estado es global y no reemplaza el detalle por medida.
- El plan puede reabrirse si un cambio de contexto obliga a revisar medidas ya tratadas.
- La elaboración y la implementación pueden avanzar a ritmos distintos.

## 8. Estado comercial o de contratación

Si el plan depende de una contratación, el estado comercial no debe confundirse con el estado operativo.

Reglas:

- `No contratada` no es un estado del plan operativo.
- `Pendiente de pago` describe una operación comercial en curso.
- `Activa` describe acceso funcional vigente.
- La activación comercial no debe borrar el estado operativo previo del plan.

## 9. Criterios de diseño

- No usar un único estado genérico para todo el ciclo del plan.
- Mantener separadas elaboración, implementación y verificación.
- Calcular la implementación desde el estado real de las medidas, no solo desde un flag comercial.
- Tratar las incidencias como actividad transversal.
- Reservar cualquier ampliación futura de estados para una necesidad funcional clara y documentada.
