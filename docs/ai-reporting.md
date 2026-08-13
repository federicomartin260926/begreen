# IA para informes del plan de sostenibilidad

Este documento describe la arquitectura y operación de la generación de textos mediante IA para el PDF general del plan de sostenibilidad.

## Alcance

La IA se utiliza para generar contenido narrativo del PDF general del plan:

- conclusión general / resumen ejecutivo;
- resumen narrativo por categoría;
- resumen narrativo de medidas futuras por categoría;
- conclusión final.

Los PDFs agrupados por departamentos u otras taxonomías no dependen de esta generación IA.

La aplicación decide qué datos se envían al proveedor y qué medidas pertenecen a cada estado. La IA no decide la clasificación funcional de las medidas.

## Proveedores soportados

La arquitectura usa una interfaz común de proveedor y actualmente contempla:

- OpenAI;
- Anthropic.

El proveedor y el modelo activo pueden configurarse desde Administración → IA, siempre que el proveedor tenga credenciales disponibles en el entorno.

Las credenciales, URLs, timeouts y límites técnicos no son editables desde el panel de administración.

## Configuración protegida

La configuración principal del prompt está en:

`app/config/ai_report_prompt.yaml`

Contiene dos bloques diferenciados:

- `technical_instructions`: reglas técnicas protegidas por la aplicación;
- `editorial_defaults`: reglas editoriales por defecto que se muestran y pueden editarse desde Administración → IA.

La versión actual del prompt técnico es `10`.

### Jerarquía de instrucciones

Las instrucciones técnicas tienen prioridad sobre cualquier regla editorial editable y sobre los datos enviados como contexto.

Las reglas editoriales son orientación de redacción de menor prioridad. Si una regla editable contradice, debilita o intenta redefinir una regla técnica, solo debe ignorarse la parte conflictiva.

Las reglas editables no pueden redefinir:

- contrato JSON;
- campos requeridos;
- claves de categorías;
- semántica de decisiones;
- fuente de verdad;
- aislamiento entre categorías;
- validación de la respuesta;
- clasificación de medidas `planned`, `not_planned` o `not_applicable`;
- qué categorías deben incluir `categoryFutureSummaries`.

Las afirmaciones editoriales sobre providers, número de llamadas API, system prompts, schemas JSON, almacenamiento, caché, hashing, código, orden de procesamiento o arquitectura interna no son autoritativas.

El uso de títulos, separadores, mayúsculas o símbolos dentro de las reglas editables no cambia su prioridad.

## Reglas editoriales

La configuración editable se divide en seis bloques:

- Generales;
- Resumen ejecutivo;
- Categorías;
- Próxima edición;
- Evitar;
- Cierre final.

La entidad persistida es `AiReportSetting`, singleton con ID `1`.

Si todavía no existe una fila persistida, `AiReportSettingResolver` utiliza los valores de `editorial_defaults` definidos en `ai_report_prompt.yaml`.

### Restaurar reglas por defecto

Cuando ya existe configuración persistida, Administración → IA muestra el botón:

`Restaurar reglas por defecto`

La acción:

- restaura los seis bloques editoriales desde `ai_report_prompt.yaml`;
- no modifica el proveedor activo;
- no modifica los modelos configurados;
- usa POST y protección CSRF;
- persiste inmediatamente los nuevos valores.

Esto permite volver en cualquier momento a un conjunto de reglas conocido y validado por la aplicación.

## Construcción del contexto

`PlanAiReportRequestBuilder` construye el contexto que recibe la IA.

Para cada medida elegible, la aplicación envía datos como:

- clave técnica de la medida;
- título visible para revisión (`nameReview`, con fallback a `name`);
- descripción;
- decisión;
- criticidad;
- observaciones;
- puntuación.

Las categorías se identifican mediante claves estables del tipo:

`category:<id>`

La aplicación agrupa cada medida usando la categoría real de `Measure`.

## Semántica de decisiones

La clasificación funcional se realiza en PHP antes de llamar al proveedor:

- `planned`: `isApplicable = true` y `willImplement = true`;
- `not_planned`: `isApplicable = true` y `willImplement = false`;
- `not_applicable`: `isApplicable = false`.

Estados incompletos no forman parte del contexto final.

La IA no puede reinterpretar estas decisiones.

## Contrato estructurado de salida

La respuesta debe ser un único objeto JSON con exactamente estos campos raíz:

```json
{
  "generalConclusion": "...",
  "categorySummaries": {},
  "categoryFutureSummaries": {},
  "finalConclusion": "..."
}
```

### `categorySummaries`

- contiene exactamente una entrada por cada categoría recibida;
- usa únicamente medidas `planned`;
- no puede usar medidas `not_planned` ni `not_applicable`.

### `categoryFutureSummaries`

- contiene únicamente categorías con al menos una medida `not_planned`;
- si no existen medidas futuras, debe ser `{}`;
- cada entrada contiene un único resumen narrativo;
- solo puede sintetizar medidas `not_planned` de esa misma categoría.

La aplicación deriva por adelantado las claves esperadas de ambas colecciones.

`AiReportOutputSchema` construye el schema estricto y `AiReportResultValidator` valida la respuesta antes de aceptarla.

## Resumen de medidas futuras

Una medida futura es exclusivamente:

`isApplicable = true && willImplement = false`

El PDF no muestra estas medidas una a una. La IA genera un único texto narrativo por categoría bajo el concepto:

`En el horizonte, para la próxima edición`

El texto debe:

- escribirse en futuro;
- usar primera persona del plural / voz de equipo;
- presentar oportunidades, prioridades o líneas de mejora;
- sintetizar varias medidas en una narrativa coherente;
- evitar enumeraciones secuenciales;
- no inventar plazos, decisiones o compromisos;
- no presentar las medidas no seleccionadas como fallos o incumplimientos;
- no explicar ni justificar por qué no se abordaron en la edición actual.

Se evitan expresamente formulaciones negativas o defensivas como “no hemos podido”, “no hemos desarrollado”, “no se ha realizado” o “queda pendiente”.

## Persistencia y reutilización

Los informes se almacenan por plan e idioma bajo el storage IA de Symfony:

`var/storage/ai/{planId}/{locale}.json`

La versión actual del formato persistido es `3`.

El archivo guarda, entre otros datos:

- versión del formato;
- ID del plan;
- locale;
- proveedor;
- modelo;
- versión del prompt;
- hash de contexto;
- fecha de generación;
- `generalConclusion`;
- `categorySummaries`;
- `categoryFutureSummaries`;
- `finalConclusion`.

## Invalidación

`PlanAiReportService` reutiliza un informe existente únicamente cuando sigue siendo válido para el contexto actual.

El hash efectivo incorpora el contexto del plan y la identidad del prompt. La identidad incluye:

- versión técnica del prompt;
- proveedor/modelo efectivo;
- fingerprint de las reglas editoriales.

Por tanto, el informe se regenera cuando cambia información relevante del plan o cambia la configuración efectiva de IA.

La subida de versión del prompt técnico también invalida automáticamente informes anteriores.

## Concurrencia

La generación usa el lock definido por la capa IA para evitar generaciones concurrentes del mismo informe.

No se realiza una llamada independiente por cada categoría ni otra llamada específica para medidas futuras. El informe completo se genera en una única operación estructurada del proveedor.

## Comportamiento ante fallos

Si el proveedor falla o devuelve una estructura inválida:

- la generación del PDF general se detiene;
- se muestra un error al usuario;
- no se persiste una respuesta parcial o inválida;
- un informe válido anterior no se sobrescribe con el resultado fallido.

Durante la validación real se comprobó este comportamiento con una respuesta temporal `529 overloaded_error` de Anthropic.

## PDF

El PDF general consume el resultado IA desde `PlanController`.

Por categoría se muestran:

1. nombre e identidad visual de la categoría;
2. resumen narrativo principal;
3. compromisos seleccionados actuales;
4. si corresponde, bloque final `En el horizonte, para la próxima edición`.

Las categorías sin medidas futuras no muestran dicho bloque.

Cuando una categoría ocupa varias páginas, el bloque futuro se reserva para la parte final de la categoría.

## Archivos principales

Configuración y prompts:

- `app/config/ai_report_prompt.yaml`
- `app/src/Service/Ai/AiReportPromptConfiguration.php`
- `app/src/Service/Ai/AiReportPromptBuilder.php`

Configuración editable:

- `app/src/Entity/AiReportSetting.php`
- `app/src/Form/AiReportSettingType.php`
- `app/src/Service/Ai/AiReportSettingResolver.php`
- `app/src/Controller/Admin/AiReportSettingController.php`
- `app/templates/admin/ai/edit.html.twig`

Contexto, contrato y validación:

- `app/src/Service/Ai/PlanAiReportRequestBuilder.php`
- `app/src/Service/Ai/AiReportOutputSchema.php`
- `app/src/Service/Ai/AiReportResultValidator.php`

DTO y persistencia:

- `app/src/Service/Ai/Dto/AiReportRequest.php`
- `app/src/Service/Ai/Dto/AiReportResult.php`
- `app/src/Service/Ai/Dto/AiReportSettings.php`
- `app/src/Service/Ai/Dto/AiStoredReport.php`

Providers y servicio:

- `app/src/Service/Ai/OpenAiReportProvider.php`
- `app/src/Service/Ai/AnthropicReportProvider.php`
- `app/src/Service/Ai/PlanAiReportService.php`

PDF:

- `app/src/Controller/Backend/PlanController.php`
- `app/templates/backend/plan/pdf/_ai_report.html.twig`
- `app/templates/backend/plan/pdf_visual.html.twig`

## Base de datos

`ai_report_setting` guarda la configuración editable.

Campos relevantes:

- `provider`;
- `open_ai_model`;
- `anthropic_model`;
- `general_instructions`;
- `executive_summary_instructions`;
- `category_instructions`;
- `future_category_instructions`;
- `avoid_instructions`;
- `final_conclusion_instructions`;
- `updated_at`.

El proyecto no usa migraciones Doctrine para estos cambios; cualquier cambio de esquema debe revisarse antes de aplicarse y no debe ejecutarse un `schema:update` global sin comprobar primero el SQL generado.

## Despliegue / checklist operativo

Antes de desplegar cambios relacionados con IA:

1. comprobar las claves del proveedor disponibles en producción;
2. revisar proveedor y modelos activos;
3. revisar `doctrine:schema:update --dump-sql` y aplicar únicamente cambios esperados;
4. limpiar/calentar caché cuando corresponda al flujo normal de deploy;
5. abrir Administración → IA y revisar las reglas persistidas;
6. decidir si se mantienen reglas personalizadas o se usa `Restaurar reglas por defecto`;
7. generar un PDF general real;
8. verificar que el informe se persiste con la versión de prompt y storage esperadas;
9. comprobar logs si el proveedor devuelve error;
10. no borrar manualmente un informe anterior solo para forzar regeneración: la invalidación debe hacerlo automáticamente.

## Validación recomendada

Tests focales principales:

- `AiReportOutputSchemaTest`
- `AiReportResultValidatorTest`
- `AiReportSettingResolverTest`
- `OpenAiReportProviderTest`
- `AnthropicReportProviderTest`
- `ConfiguredAiReportProviderTest`
- `PlanAiReportRequestBuilderTest`
- `PlanAiReportServiceTest`

Comprobaciones adicionales:

```bash
php bin/console lint:yaml config/ai_report_prompt.yaml
php bin/console lint:twig templates/admin/ai/edit.html.twig templates/backend/plan/pdf/_ai_report.html.twig templates/backend/plan/pdf_visual.html.twig
php bin/console doctrine:schema:update --dump-sql
```

En caso de diagnóstico con mucha salida, volcar los reportes a un archivo bajo `/tmp` y revisarlo fuera del repositorio.
