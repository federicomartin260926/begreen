# BEGREEN #74 — Informe de cierre QA Calculadora CO₂

**Fecha:** 20/10/2026
**Entorno:** DEV — https://begreen.tech-investments.net
**Corrección funcional desplegada:** `aa51136`
**Ámbito:** siete categorías de la Calculadora CO₂.

## 1. Resultado de la regresión

La ejecución funcional inicial finalizó con **48 PASS y 2 FAIL**
sobre 50 pruebas, incluyendo autenticación.

Los dos FAIL se investigaron individualmente:

- COM-02: defecto funcional confirmado y corregido.
- MAT-11: expectativa desactualizada respecto a una decisión
  documentada de Franc; no constituía un defecto funcional.

Las comprobaciones focalizadas posteriores fueron satisfactorias.
No se volvió a ejecutar la batería general completa.

## 2. Incidencias verificadas

| Caso | Clasificación final | Resolución |
|---|---|---|
| COM-02 Transporte | DEFECTO CORREGIDO | En altas nuevas se eliminó la categoría `local` preseleccionada en `TransportEmissionController::createDefaults()`. Commit `aa51136`, desplegado en DEV. La prueba focalizada terminó PASS. Se conserva la categoría existente en los formularios de edición. |
| MAT-11 Li-Ion 9V | FALSO POSITIVO | `MaterialUiCatalog::batteryWeight()` recoge la decisión de Franc del 08/10/2026: utilizar 0,041 kg, fila 10, siempre que el peso permanezca en el catálogo. Se corrigió el test; prueba focalizada PASS. No se modificó el cálculo. |
| CAT-01/CAT-02 | FALSO POSITIVO | Los tests exigían atributos HTML `required` no establecidos por el contrato funcional. La validación funcional de Catering ya se había confirmado; tests corregidos. |
| Identidad P2 | INCIDENCIA QA RESUELTA | El proyecto autorizado y verificado es `Proyecto Fede`, no `Fede`. El fixture comprueba su identidad antes de permitir escrituras. |

## 3. Persistencia P2

Operaciones verificadas en DEV exclusivamente sobre `Proyecto Fede`:

- Agua: creación del registro 9182.
- Agua: edición y conservación de los cambios del registro 9182.
- Agua: duplicación en el registro 9183.
- Agua: eliminación verificada del duplicado 9183.
- Catering: creación del Menú en el registro 9184.
- Catering: reapertura de 9184 y verificación de actividad Menú,
  variante Vegetariano, cantidades 10/8, vajilla reutilizable
  y observaciones. Comprobación READ-ONLY PASS.

**Limitación de la evidencia:** la suite P2 integral no obtuvo
una ejecución completa PASS. Las operaciones se verificaron en
ejecuciones sucesivas, incluida una comprobación independiente
del registro de Catering.

Por decisión del usuario, los registros de QA pueden permanecer
en DEV. No se exige limpieza rutinaria. Los registros anteriores
9180 y 9181 no se modificaron durante el cierre.

## 4. Seguridad y alcance

- Escrituras limitadas al proyecto QA autorizado.
- El guard bloquea operaciones no autorizadas.
- `writeMode.enabled` restaurado a `false` al finalizar.
- No se utilizaron fixtures de reinicio.
- No se realizaron cambios en producción.
- Los diagnósticos temporales no forman parte del commit.
- Las credenciales y sesiones locales no se versionan.

## 5. Excepciones pendientes

EX-01 a EX-06 continúan **PENDIENTE FRANC**.

No se han inventado factores, pesos ni reglas para resolverlas
y no se consideran defectos confirmados en esta tarea.

## 6. Conclusión

Las incidencias funcionales identificadas en la regresión
han quedado corregidas o aclaradas y verificadas de forma focal
en DEV.

La tarea #74 corresponde a QA interna: no se reporta a Franc
ni consume horas de su bolsa.
